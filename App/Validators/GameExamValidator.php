<?php

namespace App\Validators;

use App\Config\GameExamConstants as C;
use App\Exceptions\ValidationException;

/**
 * Validates and normalizes the nested exam payload (exam → questions →
 * choices). The generic `Validator` is not used for nested data because
 * `getSanitized()` calls strip_tags/htmlspecialchars on every value
 * (breaks on arrays and HTML-encodes Arabic text before storage, D4) and
 * `required()` uses empty() which rejects a legitimate 0 (D5).
 *
 * Text is stored plain (trimmed, tags stripped); escaping happens at
 * render time. Lengths use mb_strlen so Arabic is counted per character.
 */
class GameExamValidator
{
    private array $errors = [];

    /**
     * @param bool $metadataOnly true when the exam already has attempts (DEC-10)
     * @return array normalized ['exam' => [...], 'questions' => [...]|null]
     */
    public function validateExam(array $data, bool $metadataOnly = false): array
    {
        $this->errors = [];

        $exam = [];
        $exam['headline'] = $this->text($data, 'headline', 3, 200, true);
        $exam['description'] = $this->text($data, 'description', 0, 5000, false);

        if (!$metadataOnly) {
            $exam['stage_id'] = $this->int($data, 'stage_id', 1, 65535, true);
            $exam['duration_seconds'] = $this->int($data, 'duration_seconds', 60, 10800, true);
            $exam['pass_percentage'] = $this->number($data, 'pass_percentage', 0, 100, 50.0);
            $exam['offline_grace_seconds'] = $this->int($data, 'offline_grace_seconds', 0, 3600, false) ?? 600;
            $exam['shuffle_choices'] = $this->bool($data, 'shuffle_choices') ? 1 : 0;
            $exam['reveal_policy'] = $this->enum($data, 'reveal_policy', C::REVEAL_POLICIES, C::REVEAL_AFTER_FINAL);
            $exam['available_from'] = $this->isoDate($data, 'available_from');
            $exam['available_until'] = $this->isoDate($data, 'available_until');
            if ($exam['available_from'] !== null && $exam['available_until'] !== null
                && $exam['available_until'] <= $exam['available_from']) {
                $this->errors['available_until'] = 'available_until must be after available_from';
            }
        }

        $questions = null;
        if (!$metadataOnly) {
            $raw = $data['questions'] ?? [];
            if (!is_array($raw)) {
                $this->errors['questions'] = 'questions must be an array';
                $raw = [];
            }
            if (count($raw) > C::MAX_QUESTIONS) {
                $this->errors['questions'] = 'questions must not exceed ' . C::MAX_QUESTIONS;
            }
            $questions = [];
            foreach (array_values($raw) as $i => $q) {
                $questions[] = $this->question(is_array($q) ? $q : [], "questions.$i");
            }
        }

        if (!empty($this->errors)) {
            throw new ValidationException($this->errors);
        }

        return ['exam' => $exam, 'questions' => $questions];
    }

    /** Used by publish: a draft may have 0 questions, a published exam may not. */
    public function assertPublishable(int $questionCount): void
    {
        if ($questionCount < 1) {
            throw new ValidationException(['questions' => 'an exam needs at least one question before publishing']);
        }
    }

    /** @return int[] validated, de-duplicated ids */
    public function validateOrder(array $data): array
    {
        $order = $data['order'] ?? null;
        if (!is_array($order) || $order === []) {
            throw new ValidationException(['order' => 'order must be a non-empty array of question ids']);
        }
        $ids = [];
        foreach ($order as $id) {
            if (!is_numeric($id) || (int) $id < 1) {
                throw new ValidationException(['order' => 'order must contain question ids only']);
            }
            $ids[] = (int) $id;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new ValidationException(['order' => 'order contains duplicate ids']);
        }
        return $ids;
    }

    private function question(array $q, string $p): array
    {
        $out = [];
        $out['id'] = isset($q['id']) && is_numeric($q['id']) && (int) $q['id'] > 0 ? (int) $q['id'] : null;
        $out['type'] = $this->enum($q, 'type', C::TYPES, null, $p);
        $out['question_text'] = $this->text($q, 'question_text', 3, 1000, true, $p);
        $out['score'] = $this->int($q, 'score', 1, 100, true, $p) ?? 1;
        $second = $this->int($q, 'second_attempt_score', 0, 100, false, $p);
        if ($second !== null && $second > $out['score']) {
            $this->errors["$p.second_attempt_score"] = 'second_attempt_score must not exceed score';
        }
        $out['second_attempt_score'] = $second;
        $out['negative_score'] = $this->int($q, 'negative_score', 0, 100, false, $p) ?? 0;
        $out['retry_seconds'] = $this->int($q, 'retry_seconds', 0, 600, false, $p) ?? 30;

        $out['character_name'] = $this->text($q, 'character_name', 0, 150, false, $p);
        $out['character_description'] = $this->text($q, 'character_description', 0, 3000, false, $p);
        $out['character_image_url'] = $this->url($q, 'character_image_url', $p);
        $out['front_text'] = $this->text($q, 'front_text', 0, 3000, false, $p);
        $out['front_image_url'] = $this->url($q, 'front_image_url', $p);
        $out['back_text'] = $this->text($q, 'back_text', 0, 3000, false, $p);
        $out['back_image_url'] = $this->url($q, 'back_image_url', $p);
        $out['info_text'] = $this->text($q, 'info_text', 0, 3000, false, $p);

        if ($out['type'] === C::TYPE_CHARACTER) {
            if ($out['character_name'] === null) {
                $this->errors["$p.character_name"] = 'character_name is required for character questions';
            }
            if ($out['character_image_url'] === null && $out['character_description'] === null) {
                $this->errors["$p.character_image_url"] = 'provide a character image or description';
            }
        } elseif ($out['type'] === C::TYPE_TRANSFORMATION) {
            // Any of the 4 combinations (text/image × text/image) is valid.
            if ($out['front_text'] === null && $out['front_image_url'] === null) {
                $this->errors["$p.front"] = 'transformation A needs a text or an image';
            }
            if ($out['back_text'] === null && $out['back_image_url'] === null) {
                $this->errors["$p.back"] = 'transformation B needs a text or an image';
            }
        }

        $out['choices'] = $this->choices($q['choices'] ?? null, "$p.choices");
        return $out;
    }

    private function choices($raw, string $p): array
    {
        if (!is_array($raw)) {
            $this->errors[$p] = 'choices are required';
            return [];
        }
        $count = count($raw);
        if ($count < C::MIN_CHOICES || $count > C::MAX_CHOICES) {
            $this->errors[$p] = 'a question needs between ' . C::MIN_CHOICES . ' and ' . C::MAX_CHOICES . ' choices';
        }
        $out = [];
        $seen = [];
        $correct = 0;
        foreach (array_values($raw) as $i => $c) {
            $c = is_array($c) ? $c : [];
            $text = $this->text($c, 'choice_text', 1, 255, true, "$p.$i");
            $isCorrect = $this->bool($c, 'is_correct');
            if ($isCorrect) {
                $correct++;
            }
            if ($text !== null) {
                $key = mb_strtolower($text);
                if (isset($seen[$key])) {
                    $this->errors["$p.$i.choice_text"] = 'choices must be unique';
                }
                $seen[$key] = true;
            }
            $out[] = ['choice_text' => $text, 'is_correct' => $isCorrect ? 1 : 0];
        }
        if ($count > 0 && $correct !== 1) {
            $this->errors["$p.correct"] = 'exactly one choice must be correct';
        }
        return $out;
    }

    // ---------------------------------------------------------------- helpers

    private function text(array $d, string $f, int $min, int $max, bool $required, string $p = ''): ?string
    {
        $key = $p === '' ? $f : "$p.$f";
        if (!array_key_exists($f, $d) || $d[$f] === null) {
            if ($required) {
                $this->errors[$key] = "$f is required";
            }
            return null;
        }
        if (!is_scalar($d[$f])) {
            $this->errors[$key] = "$f must be a string";
            return null;
        }
        $value = trim(strip_tags((string) $d[$f]));
        if ($value === '') {
            if ($required) {
                $this->errors[$key] = "$f is required";
            }
            return null;
        }
        $len = mb_strlen($value, 'UTF-8');
        if ($len < $min) {
            $this->errors[$key] = "$f must be at least $min characters";
        } elseif ($len > $max) {
            $this->errors[$key] = "$f must not exceed $max characters";
        }
        return $value;
    }

    private function int(array $d, string $f, int $min, int $max, bool $required, string $p = ''): ?int
    {
        $key = $p === '' ? $f : "$p.$f";
        if (!array_key_exists($f, $d) || $d[$f] === null || $d[$f] === '') {
            if ($required) {
                $this->errors[$key] = "$f is required";
            }
            return null;
        }
        $raw = $d[$f];
        if (is_int($raw)) {
            $v = $raw;
        } elseif (is_string($raw) && preg_match('/^-?\d+$/', trim($raw))) {
            $v = (int) trim($raw);
        } elseif (is_float($raw) && floor($raw) == $raw) {
            $v = (int) $raw;
        } else {
            $this->errors[$key] = "$f must be an integer";
            return null;
        }
        if ($v < $min || $v > $max) {
            $this->errors[$key] = "$f must be between $min and $max";
        }
        return $v;
    }

    private function number(array $d, string $f, float $min, float $max, float $default): float
    {
        if (!array_key_exists($f, $d) || $d[$f] === null || $d[$f] === '') {
            return $default;
        }
        if (!is_numeric($d[$f])) {
            $this->errors[$f] = "$f must be a number";
            return $default;
        }
        $v = (float) $d[$f];
        if ($v < $min || $v > $max) {
            $this->errors[$f] = "$f must be between $min and $max";
        }
        return round($v, 2);
    }

    private function bool(array $d, string $f): bool
    {
        $v = $d[$f] ?? false;
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    private function enum(array $d, string $f, array $allowed, ?string $default, string $p = ''): ?string
    {
        $key = $p === '' ? $f : "$p.$f";
        $v = $d[$f] ?? null;
        if ($v === null || $v === '') {
            if ($default === null) {
                $this->errors[$key] = "$f is required";
            }
            return $default;
        }
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->errors[$key] = "$f must be one of: " . implode(', ', $allowed);
            return $default;
        }
        return $v;
    }

    private function url(array $d, string $f, string $p): ?string
    {
        $v = $d[$f] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }
        $v = is_string($v) ? trim($v) : '';
        if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v) || mb_strlen($v) > 255) {
            $this->errors["$p.$f"] = "$f must be a valid http(s) URL";
            return null;
        }
        return $v;
    }

    private function isoDate(array $d, string $f): ?string
    {
        $v = $d[$f] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        try {
            $dt = (new \DateTimeImmutable((string) $v))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            $this->errors[$f] = "$f must be an ISO-8601 date";
            return null;
        }
        return $dt->format('Y-m-d H:i:s');
    }
}
