<?php
namespace App\Models;

use RuntimeException;

/** خطأ إدخال يحمل رسالة مستقلة لكل حقل في نموذج المندوب. */
class RepSubmissionValidationException extends RuntimeException
{
    public function __construct(private array $errors)
    {
        parent::__construct((string) reset($errors));
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
