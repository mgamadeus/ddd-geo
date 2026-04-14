<?php

namespace DDD\Domain\Common\Entities\Addresses;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\Validator\Constraint;

/**
 * Postal Address constraint.
 *
 * @Annotation
 */
#[Attribute]
class PostalAddressConstraint extends Constraint
{
    use BaseAttributeTrait;

    public string $message = 'The Address is not correct';

    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }
}