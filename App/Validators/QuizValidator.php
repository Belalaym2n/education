<?php

namespace App\Validators;

use App\Models\QuestionType;
use App\Models\QuizStatus;

/**
 * Extends your existing Validator (same $data/$errors pattern) with the
 * extra checks a quiz needs. Kept as a subclass rather than editing
 * Validator directly so the shared class stays generic/reusable.
 */
class QuizValidator extends Validator
{

 
     public function validateDate(string $field): bool
    {
        if (!isset($this->data[$field]) || empty($this->data[$field])) {
            $this->addError($field, "$field is required");
            return false;
        } 

        $value = $this->data[$field];
        $d = \DateTime::createFromFormat('Y-m-d', $value);

        if (!$d || $d->format('Y-m-d') !== $value) {
            $this->addError($field, "$field must be a valid date (YYYY-MM-DD)");
            return false;
        }

        return true;
    }

    public function validateTime(string $field, bool $required = false): bool
    {
        if (!isset($this->data[$field]) || $this->data[$field] === null || $this->data[$field] === '') {
            if ($required) {
                $this->addError($field, "$field is required");
                return false;
            }
            return true;
        }

        $value = $this->data[$field];
        $d = \DateTime::createFromFormat('H:i:s', $value) ?: \DateTime::createFromFormat('H:i', $value);

        if (!$d) {
            $this->addError($field, "$field must be a valid time (HH:MM or HH:MM:SS)");
            return false;
        }

        return true;
    }

    public function validateEnum(string $field, array $allowed): bool
    {
        if (!isset($this->data[$field])) {
            $this->addError($field, "$field is required");
            return false;
        }

        if (!in_array($this->data[$field], $allowed, true)) {
            $this->addError($field, "$field must be one of: " . implode(', ', $allowed));
            return false;
        }

        return true;
    }

    public function validateBoolLike(string $field): bool
    {
        if (!array_key_exists($field, $this->data)) {
            return true; // optional booleans default server-side
        }

        $value = $this->data[$field];
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', true, false], true)) {
            $this->addError($field, "$field must be a boolean");
            return false;
        }

        return true;
    }

    /** Validates end_date/end_time is not before start_date/start_time. */
    public function validateScheduleOrder(): bool
    {
        if (!isset($this->data['start_date'], $this->data['end_date'])) {
            return true; // required() already caught missing fields
        }

        $start = $this->data['start_date'] . ' ' . ($this->data['start_time'] ?? '00:00:00');
        $end = $this->data['end_date'] . ' ' . ($this->data['end_time'] ?? '23:59:59');

        if (strtotime($end) <= strtotime($start)) {
            $this->addError('end_date', 'end_date/end_time must be after start_date/start_time');
            return false;
        }

        return true;
    }

    public function validateQuizStatus(string $field): bool
    {
        return $this->validateEnum($field, QuizStatus::ALL);
    }

    /**
     * Validates the whole questions[] payload sent from the local-only
     * question builder on final Submit — structural checks per type.
     */
    public function validateQuestionsArray(string $field): bool
    {
        if (!isset($this->data[$field]) || !is_array($this->data[$field])) {
            $this->addError($field, "$field must be an array");
            return false;
        }

        if (count($this->data[$field]) === 0) {
            $this->addError($field, 'A quiz must have at least one question before publishing');
            return false;
        }

        foreach ($this->data[$field] as $index => $question) {
            $prefix = "questions[$index]";

            if (empty($question['type']) || !QuestionType::isValid($question['type'])) {
                $this->addError($prefix . '.type', "$prefix.type is invalid or missing");
                continue;
            }

            if (empty($question['question_text'])) {
                $this->addError($prefix . '.question_text', "$prefix.question_text is required");
            }

            if (!isset($question['marks']) || !is_numeric($question['marks']) || $question['marks'] <= 0) {
                $this->addError($prefix . '.marks', "$prefix.marks must be a positive number");
            }

            if (QuestionType::isChoiceBased($question['type'])) {
                $choices = $question['choices'] ?? [];
                if (!is_array($choices) || count($choices) < 2) {
                    $this->addError($prefix . '.choices', "$prefix must have at least 2 choices");
                    continue;
                }

                $correctCount = 0;
                foreach ($choices as $choice) {
                    if (!empty($choice['is_correct'])) {
                        $correctCount++;
                    }
                }

                if ($correctCount === 0) {
                    $this->addError($prefix . '.choices', "$prefix must have at least one correct choice");
                }

                if ($question['type'] === 'mcq' && $correctCount > 1) {
                    $this->addError($prefix . '.choices', "$prefix (mcq) must have exactly one correct choice — use multi_select for more");
                }
            } 
        }

        return !$this->hasErrors();
    }

    /**
     * Your base Validator stores errors in a private property, so this
     * subclass mirrors the same array shape via the public API it already
     * exposes (getErrors/hasErrors) rather than reaching into private state.
     */
    private function addError(string $field, string $message): void
    {
        $this->errorsBag[$field] = $this->errorsBag[$field] ?? $message;
    }

    /** @var array<string,string> local mirror merged into getErrors() output */
    private array $errorsBag = [];

    public function getErrors()
    {
        return array_merge(parent::getErrors(), $this->errorsBag);
    }

    public function hasErrors()
    {
        return parent::hasErrors() || !empty($this->errorsBag);
    }
}
