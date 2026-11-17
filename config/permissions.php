<?php

// Permission catalog (Session 80) — every menu item of every panel is one permission.
//   key       = the page's frontend path (also what the sidebar uses)
//   group     = shown as a heading in Admin → Users → User Permissions
//   roles     = roles that have it by default (keeps existing behaviour; admin always has everything)
//   apis      = backend endpoints that page needs: "METHOD /api/path" (METHOD may be *).
//               A path matches itself and anything below it ("/api/x" matches "/api/x/5").
//               Only list what the page really calls — e.g. Exam Fee Collection deliberately does NOT
//               include PUT/DELETE /api/admin/exam-fees/{id} (payment edit/delete stays admin-only).
//   grantable = false → admin-only, can never be given to another user (user management, permissions,
//               and the admin-exclusive exam modules).
// Dashboards are not listed: a role's dashboard is always available to that role.

return [
    // ---------------- Admin → Exam Management ----------------
    ['key' => '/p_Xk7mN/results', 'label' => 'All Results', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/results', 'GET /api/admin/subjects/tree']],
    ['key' => '/p_Xk7mN/result-summary', 'label' => 'Result Summary', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['POST /api/admin/result-summary']],
    ['key' => '/p_Xk7mN/transcript', 'label' => 'Transcript', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['POST /api/admin/transcript']],
    ['key' => '/p_Xk7mN/transcript-verify', 'label' => 'Transcript QR Verify', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/transcript-verify']],
    ['key' => '/p_Xk7mN/exams-groups', 'label' => 'Exams & Groups', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/exams', '* /api/admin/catalog'], 'grantable' => false],
    ['key' => '/p_Xk7mN/exam-routine', 'label' => 'Exam Routine', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/exam-routines']],
    ['key' => '/p_Xk7mN/exam-paper-selection', 'label' => 'Exam Paper Selection', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/exam-papers', 'GET /api/admin/subjects/tree']],
    ['key' => '/p_Xk7mN/admit-card', 'label' => 'Admit Card', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/admit-card', '* /api/admin/admit-card-config', 'GET /api/admin/exam-papers']],
    ['key' => '/p_Xk7mN/mark-entry-form', 'label' => 'Mark Entry Form', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/mark-entry-form']],
    ['key' => '/p_Xk7mN/exam-fee-set', 'label' => 'Exam Fee Set', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/exam-fees/amount', 'GET /api/admin/exam-fees/amounts', 'GET /api/admin/exams', 'POST /api/admin/exams']],
    ['key' => '/p_Xk7mN/due-rules', 'label' => 'Fee Due Rules (mark entry + result hold)', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/due-rules'], 'grantable' => false],
    ['key' => '/p_Xk7mN/exam-fee-paid-list', 'label' => 'Exam Fee Paid List (subject-wise)', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['POST /api/admin/exam-fees/paid-by-subject']],
    ['key' => '/p_Xk7mN/exam-fee-waiver', 'label' => 'Exam Fee Waiver', 'group' => 'Admin › Internal Exam Management', 'roles' => ['exam_controller'], 'apis' => ['POST /api/admin/exam-fees/waiver', 'GET /api/admin/students/lookup', 'POST /api/admin/exam-fees/status']],
    ['key' => '/p_Xk7mN/exam-fee-collection', 'label' => 'Exam Fee Collection (collect + own report)', 'group' => 'Admin › Internal Exam Management', 'roles' => ['exam_controller'], 'apis' => ['POST /api/admin/exam-fees/collect', 'POST /api/admin/exam-fees/report', 'POST /api/admin/exam-fees/status', 'GET /api/admin/students/lookup']],
    // Same page, report tab only (Administration's long-standing access: view own collection report, no collecting)
    ['key' => '/p_Xk7mN/exam-fee-collection#report', 'label' => 'Exam Fee Collection – Reports only', 'group' => 'Admin › Internal Exam Management', 'roles' => ['administration'], 'apis' => ['POST /api/admin/exam-fees/report']],
    ['key' => '/p_Xk7mN/seat-plan', 'label' => 'Exam Seat Plan', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/seat-plan'], 'grantable' => false],
    ['key' => '/p_Xk7mN/exam-duty', 'label' => 'Hall Duty Allocation', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/exam-duty', '* /api/admin/exam-duty-settings'], 'grantable' => false],
    ['key' => '/p_Xk7mN/duty-letters', 'label' => 'Duty Appointment Letters', 'group' => 'Admin › Internal Exam Management', 'roles' => [], 'apis' => ['* /api/admin/exam-duty/letters'], 'grantable' => false],
    // ---------------- Admin → Board Exam Management (Session 99) ----------------
    ['key' => '/p_Xk7mN/board/exams', 'label' => 'Board Exams & Venues', 'group' => 'Admin › Board Exam Management', 'roles' => [], 'apis' => ['* /api/admin/board-exams', '* /api/admin/board-venues', '* /api/admin/board-rooms'], 'grantable' => false],
    ['key' => '/p_Xk7mN/board/candidates', 'label' => 'Board Candidates', 'group' => 'Admin › Board Exam Management', 'roles' => [], 'apis' => ['* /api/admin/board-exams', '* /api/admin/board-candidates'], 'grantable' => false],
    ['key' => '/p_Xk7mN/board/routine', 'label' => 'Board Routine', 'group' => 'Admin › Board Exam Management', 'roles' => [], 'apis' => ['* /api/admin/board-exams', '* /api/admin/board-routine'], 'grantable' => false],
    ['key' => '/p_Xk7mN/board/seat-plan', 'label' => 'Board Seat Plan', 'group' => 'Admin › Board Exam Management', 'roles' => [], 'apis' => ['* /api/admin/board-exams'], 'grantable' => false],
    ['key' => '/p_Xk7mN/board/attendance', 'label' => 'Board Attendance Sheets', 'group' => 'Admin › Board Exam Management', 'roles' => [], 'apis' => ['GET /api/admin/board-exams'], 'grantable' => false],
    ['key' => '/p_Xk7mN/board/external-teachers', 'label' => 'External Teachers', 'group' => 'Admin › Board Exam Management', 'roles' => [], 'apis' => ['* /api/admin/board-external-teachers'], 'grantable' => false],
    ['key' => '/p_Xk7mN/board/hall-duty', 'label' => 'Board Hall Duty', 'group' => 'Admin › Board Exam Management', 'roles' => [], 'apis' => ['GET /api/admin/board-exams', '* /api/admin/board-duty'], 'grantable' => false],
    ['key' => '/p_Xk7mN/board/duty-letters', 'label' => 'Board Duty Letters', 'group' => 'Admin › Board Exam Management', 'roles' => [], 'apis' => ['GET /api/admin/board-exams', '* /api/admin/board-duty'], 'grantable' => false],

    // ---------------- Admin → content & people ----------------
    ['key' => '/p_Xk7mN/notices', 'label' => 'Notices', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/notices']],
    ['key' => '/p_Xk7mN/custom-fields', 'label' => 'Custom Entry Fields', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/custom-fields']],
    ['key' => '/p_Xk7mN/subjects', 'label' => 'Subjects', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/subjects', '* /api/admin/subject-parts']],
    ['key' => '/p_Xk7mN/user-history', 'label' => 'User History', 'group' => 'Admin', 'roles' => [], 'apis' => ['GET /api/admin/changelog']],
    ['key' => '/p_Xk7mN/routines', 'label' => 'Routines', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/routines']],
    ['key' => '/p_Xk7mN/syllabus', 'label' => 'Syllabus', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/syllabus']],
    ['key' => '/p_Xk7mN/teachers', 'label' => 'Teachers', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/teachers-staff/teacher', 'GET /api/admin/teachers-staff/teachers']],
    ['key' => '/p_Xk7mN/staff', 'label' => 'Staff', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/teachers-staff/staff']],
    ['key' => '/p_Xk7mN/students', 'label' => 'All Students', 'group' => 'Admin › Student Management', 'roles' => [], 'apis' => ['* /api/admin/students']],
    ['key' => '/p_Xk7mN/students/add', 'label' => 'Add Student', 'group' => 'Admin › Student Management', 'roles' => [], 'apis' => ['GET /api/admin/students', 'POST /api/admin/students', 'GET /api/admin/custom-fields']],
    ['key' => '/p_Xk7mN/students/photos', 'label' => 'Bulk Photo Upload', 'group' => 'Admin › Student Management', 'roles' => [], 'apis' => ['GET /api/admin/students', 'POST /api/admin/students']],
    ['key' => '/p_Xk7mN/class-change', 'label' => 'Class Change', 'group' => 'Admin › Student Management', 'roles' => [], 'apis' => ['POST /api/admin/students/bulk-change-class']],
    ['key' => '/p_Xk7mN/student-info', 'label' => 'Student Info', 'group' => 'Admin › Student Management', 'roles' => [], 'apis' => ['* /api/admin/student-info']],
    ['key' => '/p_Xk7mN/governing-body', 'label' => 'Governing Body', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/governing-body']],
    ['key' => '/p_Xk7mN/principals', 'label' => 'Principal & Vice-Principal', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/principals', '* /api/admin/settings/principal_font']],
    ['key' => '/p_Xk7mN/teachers-council', 'label' => 'Teachers Council', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/teachers-council']],
    ['key' => '/p_Xk7mN/career-club', 'label' => 'Career Club', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/career-club']],
    ['key' => '/p_Xk7mN/co-curricular', 'label' => 'Co-Curricular', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/co-curricular']],
    ['key' => '/p_Xk7mN/attendance', 'label' => 'Attendance (all teachers)', 'group' => 'Admin', 'roles' => [], 'apis' => ['GET /api/admin/attendance/teachers', '* /api/teacher/attendance']],
    ['key' => '/p_Xk7mN/achievements', 'label' => 'Achievements', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/achievements']],
    ['key' => '/p_Xk7mN/academic-approvals', 'label' => 'Academic Approvals', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/academic-approvals']],
    ['key' => '/p_Xk7mN/forms', 'label' => 'Forms', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/forms']],
    ['key' => '/p_Xk7mN/pages', 'label' => 'Pages (website content)', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/pages']],
    ['key' => '/p_Xk7mN/gallery', 'label' => 'Gallery', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/gallery']],
    ['key' => '/p_Xk7mN/media', 'label' => 'Media', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/media']],
    ['key' => '/p_Xk7mN/users', 'label' => 'Users & Permissions', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/users', '* /api/admin/permissions'], 'grantable' => false],
    ['key' => '/p_Xk7mN/tuition-fees', 'label' => 'Tuition Fees', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/tuition-fees', '* /api/admin/fees']],
    ['key' => '/p_Xk7mN/transactions', 'label' => 'Transactions', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/transactions']],
    ['key' => '/p_Xk7mN/leave-management', 'label' => 'Leave Management (all)', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/leave-management', '* /api/admin/leave-allocations', '* /api/admin/leave-taken']],
    ['key' => '/p_Xk7mN/collected-summary', 'label' => 'Collected Summary', 'group' => 'Admin', 'roles' => [], 'apis' => ['GET /api/admin/fees/collected-summary']],
    ['key' => '/p_Xk7mN/contact', 'label' => 'Contact Info', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/contact']],
    ['key' => '/p_Xk7mN/font-settings', 'label' => 'Font Settings', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/settings']],
    ['key' => '/p_Xk7mN/settings', 'label' => 'Site Settings', 'group' => 'Admin', 'roles' => [], 'apis' => ['* /api/admin/settings']],
    ['key' => '/p_Xk7mN/id-cards', 'label' => 'ID Cards', 'group' => 'Admin', 'roles' => [], 'apis' => ['GET /api/admin/id-cards']],

    // ---------------- Teacher ----------------
    ['key' => '/p_R2t9b/upload-result', 'label' => 'Upload Result', 'group' => 'Teacher', 'roles' => ['teacher'], 'apis' => ['* /api/teacher/results']],
    ['key' => '/p_R2t9b/update-result', 'label' => 'Update Result', 'group' => 'Teacher', 'roles' => ['teacher'], 'apis' => ['* /api/teacher/results', 'GET /api/teacher/subjects']],
    ['key' => '/p_R2t9b/attendance', 'label' => 'Attendance', 'group' => 'Teacher', 'roles' => ['teacher'], 'apis' => ['* /api/teacher/attendance', 'GET /api/teacher/subjects']],
    ['key' => '/p_R2t9b/leave-management', 'label' => 'Leave Management', 'group' => 'Teacher', 'roles' => ['teacher'], 'apis' => ['* /api/teacher/leave']],
    ['key' => '/p_R2t9b/form-download', 'label' => 'Form Download', 'group' => 'Teacher', 'roles' => ['teacher'], 'apis' => ['GET /api/teacher/forms']],

    // ---------------- Staff ----------------
    ['key' => '/p_L8p1x/leave-management', 'label' => 'Leave Management', 'group' => 'Staff', 'roles' => ['staff'], 'apis' => ['* /api/staff/leave']],

    // ---------------- Exam Controller ----------------
    ['key' => '/p_H3v5d/upload-result', 'label' => 'Upload Result', 'group' => 'Exam Controller', 'roles' => ['exam_controller'], 'apis' => ['* /api/teacher/results', '* /api/exam-controller/results/upload']],
    ['key' => '/p_H3v5d/approve-result', 'label' => 'Approve Result', 'group' => 'Exam Controller', 'roles' => ['exam_controller'], 'apis' => ['* /api/exam-controller/results']],
    ['key' => '/p_H3v5d/transcript', 'label' => 'Transcript', 'group' => 'Exam Controller', 'roles' => ['exam_controller'], 'apis' => ['POST /api/admin/transcript']],

    // ---------------- Principal ----------------
    ['key' => '/p_W4q6z/add-teacher', 'label' => 'Add Teacher', 'group' => 'Principal', 'roles' => ['principal'], 'apis' => ['* /api/principal/teachers']],
    ['key' => '/p_W4q6z/add-staff', 'label' => 'Add Staff', 'group' => 'Principal', 'roles' => ['principal'], 'apis' => ['* /api/principal/staff']],
    ['key' => '/p_W4q6z/result-publish', 'label' => 'Result Publish', 'group' => 'Principal', 'roles' => ['principal'], 'apis' => ['* /api/principal/results']],
    ['key' => '/p_W4q6z/tuition-fee', 'label' => 'Tuition Fee', 'group' => 'Principal', 'roles' => ['principal'], 'apis' => ['* /api/principal/fees']],
    ['key' => '/p_W4q6z/leave-management', 'label' => 'Leave Management', 'group' => 'Principal', 'roles' => ['principal'], 'apis' => ['* /api/principal/leave']],

    // ---------------- Administration ----------------
    ['key' => '/p_F7c2j/notices', 'label' => 'Notices', 'group' => 'Administration', 'roles' => ['administration'], 'apis' => ['* /api/admin-panel/notices']],
    ['key' => '/p_F7c2j/results', 'label' => 'Results', 'group' => 'Administration', 'roles' => ['administration'], 'apis' => ['* /api/admin-panel/results']],
    ['key' => '/p_F7c2j/syllabus', 'label' => 'Syllabus', 'group' => 'Administration', 'roles' => ['administration'], 'apis' => ['* /api/admin-panel/syllabus']],
    ['key' => '/p_F7c2j/routine', 'label' => 'Routine', 'group' => 'Administration', 'roles' => ['administration'], 'apis' => ['* /api/admin-panel/routines']],
    ['key' => '/p_F7c2j/fee-collection', 'label' => 'Fee Collection', 'group' => 'Administration', 'roles' => ['administration'], 'apis' => ['* /api/admin-panel/fees']],
    ['key' => '/p_F7c2j/collected-summary', 'label' => 'Collected Summary', 'group' => 'Administration', 'roles' => ['administration'], 'apis' => ['GET /api/admin-panel/fees/collected-summary']],
    ['key' => '/p_F7c2j/students', 'label' => 'All Students', 'group' => 'Administration › Student Management', 'roles' => ['administration'], 'apis' => ['* /api/admin/students']],
    ['key' => '/p_F7c2j/students/add', 'label' => 'Add Student', 'group' => 'Administration › Student Management', 'roles' => ['administration'], 'apis' => ['GET /api/admin/students', 'POST /api/admin/students', 'GET /api/admin/custom-fields']],
    ['key' => '/p_F7c2j/forms', 'label' => 'Forms', 'group' => 'Administration', 'roles' => ['administration'], 'apis' => ['* /api/admin-panel/forms']],

    // ---------------- Student ----------------
    ['key' => '/p_G9n4s/results', 'label' => 'My Results', 'group' => 'Student', 'roles' => ['student'], 'apis' => ['GET /api/student/results']],
    ['key' => '/p_G9n4s/attendance', 'label' => 'My Attendance', 'group' => 'Student', 'roles' => ['student'], 'apis' => ['GET /api/student/attendance']],
    ['key' => '/p_G9n4s/pay-fees', 'label' => 'Pay Fees', 'group' => 'Student', 'roles' => ['student'], 'apis' => ['* /api/student/fees', '* /api/student/pay-fee']],
];
