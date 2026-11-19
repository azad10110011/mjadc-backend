<?php

/**
 * Result publishing workflow — state machine + edit rules (Session 113).
 *
 * Lifecycle:
 *   draft      → teacher is entering marks            (teacher + admin may edit)
 *   submitted  → teacher submitted to Exam Controller (exam_controller + admin may edit; teacher LOCKED)
 *   approved   → EC approved / sent to Principal      (admin only may edit; EC + teacher LOCKED)
 *   published  → Principal published                  (admin only may edit)
 *
 * Sending a result back moves it down a stage so the receiving role can edit again.
 * Admin is never locked: admin may edit any mark and move any result to any stage.
 *
 * Needs calculateGradeFromTotal() (transcript.php) and the teacherResult* helpers
 * (teacher/results.php) at call time — both are loaded before the router dispatches.
 */

if (!function_exists('resultStages')) {

    function resultStages(): array
    {
        return ['draft', 'submitted', 'approved', 'published'];
    }

    function resultUserRoles(array $user): array
    {
        return array_map('strval', (array)($user['roles'] ?? []));
    }

    function resultIsAdmin(array $user): bool
    {
        return in_array('admin', resultUserRoles($user), true);
    }

    /** Can this user edit the MARKS of a row currently in $status? */
    function resultCanEditMarks(array $user, string $status): bool
    {
        if (resultIsAdmin($user)) return true;        // admin: any stage, incl. published
        if ($status === 'published') return false;    // published: admin only
        $roles = resultUserRoles($user);
        if (in_array('exam_controller', $roles, true)) return in_array($status, ['draft', 'submitted'], true);
        if (in_array('teacher', $roles, true)) return $status === 'draft';
        return false;                                  // principal / others never edit marks
    }

    /** Why an edit is blocked, for the error message. */
    function resultLockReason(string $status): string
    {
        switch ($status) {
            case 'submitted': return 'These marks were submitted to the Exam Controller and can no longer be changed here.';
            case 'approved':  return 'These marks were approved and sent to the Principal. Only an admin can change them now.';
            case 'published': return 'These marks are published. Only an admin can change them now.';
            default:          return 'These marks are locked.';
        }
    }

    /**
     * Recompute one row's marks/grade/gpa from part data, using the subject's part pass-marks
     * (same rule as the teacher upload: absent in any part, or below a part pass-mark → F).
     * @return array{0:float,1:float,2:float,3:float,4:string,5:float,6:?string,7:?string}
     *         [cq, mcq, practical, total, grade, gpa, partsJson|null, absentJson|null]
     */
    function resultComputeRow(string $subjectKey, array $partsData, array $absentIn): array
    {
        $subjectName = $subjectKey;
        $paperName = '';
        if (strpos($subjectKey, ' - ') !== false) {
            [$subjectName, $paperName] = array_map('trim', explode(' - ', $subjectKey, 2));
        }

        $partConfigs = [];
        foreach (teacherResultPartsCandidates($subjectName, $paperName !== '' ? $paperName : null) as $cand) {
            if (teacherResultSameSubject($cand, 'ICT')) {
                $rows = Database::fetchAll("SELECT part_name, pass_mark FROM subject_parts WHERE subject IN ('ICT','Information and Communication Technology','Information and Communications Technology') ORDER BY sort_order ASC, id ASC");
            } else {
                $rows = Database::fetchAll("SELECT part_name, pass_mark FROM subject_parts WHERE subject = ? ORDER BY sort_order ASC, id ASC", [$cand]);
            }
            if ($rows) { $partConfigs = $rows; break; }
        }

        $lower = [];
        foreach ($partsData as $k => $v) $lower[strtolower((string)$k)] = $v;
        $cq = (float)($lower['cq'] ?? 0);
        $mcq = (float)($lower['mcq'] ?? 0);
        $practical = (float)($lower['practical'] ?? 0);

        if (!empty($partConfigs)) {
            $total = 0.0; $failed = false; $allAbsent = true;
            foreach ($partConfigs as $p) {
                $pn = $p['part_name'];
                $isAbs = in_array($pn, $absentIn, true);
                if (!$isAbs) $allAbsent = false;
                $val = (float)($partsData[$pn] ?? 0);
                $total += $val;
                if ($isAbs || $val < (float)$p['pass_mark']) $failed = true;
            }
            if ($allAbsent || $failed) { $grade = 'F'; $gpa = 0.00; }
            else { $g = calculateGradeFromTotal($total); $grade = $g['grade']; $gpa = $g['points']; }
        } else {
            $total = $cq + $mcq + $practical;
            if (!empty($absentIn)) { $grade = 'F'; $gpa = 0.00; }
            else { $g = calculateGradeFromTotal($total); $grade = $g['grade']; $gpa = $g['points']; }
        }

        return [
            $cq, $mcq, $practical, $total, $grade, $gpa,
            !empty($partsData) ? json_encode($partsData) : null,
            !empty($absentIn) ? json_encode($absentIn) : null,
        ];
    }

    /**
     * Names of the compulsory subjects that made a transcript fail (grade F or Absent) — the same
     * rule transcriptBuild() uses to make the whole result F. The optional subject never fails it.
     */
    function resultFailSubjects(array $transcript): array
    {
        $out = [];
        foreach ((array)($transcript['subjects'] ?? []) as $s) {
            if (!empty($s['is_optional'])) continue;
            if (in_array(strtoupper((string)($s['grade'] ?? '')), ['F', 'ABSENT'], true)) $out[] = (string)$s['subject'];
        }
        return $out;
    }

    /**
     * Full column set when an ADMIN moves a result to $toStatus — stamps the actor for the new
     * stage and clears the stamps of any higher stage, so a downgrade doesn't leave stale
     * "published_by"/"approved_by" values. Returns null for an invalid status.
     */
    function resultStatusFullStamp(string $toStatus, int $byUserId): ?array
    {
        switch ($toStatus) {
            case 'draft':
                return ['status' => 'draft', 'submitted_by' => null, 'approved_by' => null, 'published_by' => null, 'published_at' => null];
            case 'submitted':
                return ['status' => 'submitted', 'submitted_by' => $byUserId, 'approved_by' => null, 'published_by' => null, 'published_at' => null];
            case 'approved':
                return ['status' => 'approved', 'approved_by' => $byUserId, 'published_by' => null, 'published_at' => null];
            case 'published':
                return ['status' => 'published', 'published_by' => $byUserId, 'published_at' => date('Y-m-d H:i:s')];
            default:
                return null;
        }
    }
}
