<?php
// backend/utils/response_helper.php
// Shared response and input utilities (auto-included via middleware/auth.php)
// This file documents patterns used across all API endpoints.

// ── Pagination helper ──────────────────────────────────────────
/**
 * Build a standard pagination meta array.
 * Usage: $meta = paginationMeta($db, 'treatments', 'WHERE owner_id=?', [$ownerId], $page, $limit);
 */
function paginationMeta(PDO $db, string $table, string $where, array $params,
                        int $page, int $limit): array {
    $count = $db->prepare("SELECT COUNT(*) FROM `$table` $where");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    return [
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $limit,
        'last_page'  => (int) ceil($total / $limit),
        'has_more'   => ($page * $limit) < $total,
    ];
}

// ── Input sanitisation ─────────────────────────────────────────
function sanitizeString(?string $s, int $maxLen = 255): string {
    if ($s === null) return '';
    return mb_substr(trim(strip_tags($s)), 0, $maxLen);
}

function sanitizeInt(mixed $v, int $default = 0): int {
    return filter_var($v, FILTER_VALIDATE_INT) !== false ? (int) $v : $default;
}

function sanitizeFloat(mixed $v, float $default = 0.0): float {
    return filter_var($v, FILTER_VALIDATE_FLOAT) !== false ? (float) $v : $default;
}

// ── Coordinate validation ──────────────────────────────────────
function isValidLat(float $lat): bool { return $lat >= -90  && $lat <= 90;  }
function isValidLng(float $lng): bool { return $lng >= -180 && $lng <= 180; }

// ── Date helpers ───────────────────────────────────────────────
function isValidDate(?string $date): bool {
    if (!$date) return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function isValidDatetime(?string $dt): bool {
    if (!$dt) return false;
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $dt);
    return (bool) $d;
}


// ═══════════════════════════════════════════════════════════════════
// backend/utils/input_validator.php
// Declarative validation helper
// ═══════════════════════════════════════════════════════════════════

class Validator {
    private array $data;
    private array $errors = [];

    public function __construct(array $data) {
        $this->data = $data;
    }

    /** Validate and return instance (fluent) */
    public static function make(array $data): self {
        return new self($data);
    }

    public function required(string $field, string $label = ''): self {
        if (empty($this->data[$field])) {
            $this->errors[$field] = ($label ?: $field) . ' is required';
        }
        return $this;
    }

    public function minLength(string $field, int $min, string $label = ''): self {
        $val = $this->data[$field] ?? '';
        if (strlen($val) < $min) {
            $this->errors[$field] = ($label ?: $field) . " must be at least $min characters";
        }
        return $this;
    }

    public function in(string $field, array $allowed): self {
        if (!in_array($this->data[$field] ?? '', $allowed, true)) {
            $this->errors[$field] = "Invalid value for $field";
        }
        return $this;
    }

    public function numeric(string $field): self {
        if (isset($this->data[$field]) && !is_numeric($this->data[$field])) {
            $this->errors[$field] = "$field must be numeric";
        }
        return $this;
    }

    public function phone(string $field): self {
        $val = $this->data[$field] ?? '';
        if (!preg_match('/^\+?[0-9]{10,15}$/', $val)) {
            $this->errors[$field] = 'Invalid phone number';
        }
        return $this;
    }

    public function passes(): bool   { return empty($this->errors); }
    public function fails(): bool    { return !empty($this->errors); }
    public function errors(): array  { return $this->errors; }

    /** Abort with 422 if validation fails */
    public function abortIfFails(): void {
        if ($this->fails()) {
            respond(false, array_values($this->errors)[0], ['errors' => $this->errors], 422);
        }
    }
}

// ── Usage example (in any API file): ──────────────────────────
// Validator::make(body())
//     ->required('animal_id')
//     ->required('symptoms')
//     ->in('visit_type', ['home_visit','clinic','telemedicine'])
//     ->abortIfFails();