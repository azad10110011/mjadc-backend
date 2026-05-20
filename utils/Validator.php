<?php

class Validator
{
    private array $errors = [];
    private array $data;

    public function __construct(?array $data)
    {
        $this->data = $data ?? [];
    }

    public function required(string $field, string $label = ''): self
    {
        $label = $label ?: $field;
        if (!isset($this->data[$field]) || trim((string)$this->data[$field]) === '') {
            $this->errors[$field][] = "{$label} is required";
        }
        return $this;
    }

    public function email(string $field): self
    {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
                $this->errors[$field][] = "Invalid email format";
            }
        }
        return $this;
    }

    public function minLength(string $field, int $min): self
    {
        if (isset($this->data[$field]) && strlen($this->data[$field]) < $min) {
            $this->errors[$field][] = "Must be at least {$min} characters";
        }
        return $this;
    }

    public function maxLength(string $field, int $max): self
    {
        if (isset($this->data[$field]) && strlen($this->data[$field]) > $max) {
            $this->errors[$field][] = "Must not exceed {$max} characters";
        }
        return $this;
    }

    public function numeric(string $field): self
    {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!is_numeric($this->data[$field])) {
                $this->errors[$field][] = "Must be numeric";
            }
        }
        return $this;
    }

    public function inArray(string $field, array $allowed): self
    {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!in_array($this->data[$field], $allowed)) {
                $this->errors[$field][] = "Invalid value. Allowed: " . implode(', ', $allowed);
            }
        }
        return $this;
    }

    public function date(string $field): self
    {
        if (isset($this->data[$field]) && !empty($this->data[$field])) {
            if (!strtotime($this->data[$field])) {
                $this->errors[$field][] = "Invalid date format";
            }
        }
        return $this;
    }

    public function file(string $field, array $allowedTypes, int $maxSize = 5242880): self
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return $this;
        }

        $file = $_FILES[$field];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedTypes)) {
            $this->errors[$field][] = "File type not allowed. Allowed: " . implode(', ', $allowedTypes);
        }

        if ($file['size'] > $maxSize) {
            $this->errors[$field][] = "File too large. Max: " . ($maxSize / 1048576) . "MB";
        }

        return $this;
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validate(): array
    {
        if (!$this->passes()) {
            Response::validationError($this->errors);
        }
        return $this->data;
    }

    public function get(string $field, $default = null)
    {
        return $this->data[$field] ?? $default;
    }
}

function validate(?array $data): Validator
{
    return new Validator($data ?? []);
}
