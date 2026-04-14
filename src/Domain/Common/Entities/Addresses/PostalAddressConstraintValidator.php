<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Addresses;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use libphonenumber\NumberParseException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Throwable;

/**
 * Phone number validator.
 */
class PostalAddressConstraintValidator extends ConstraintValidator
{
    /** @var string Error message for not Rooftop precision */
    public const ERROR_NO_ROOFTOP_PRECISION = 'The address is not precise enough';

    /** @var string Error message for not found address */
    public const ADDRESS_NOT_FOUND = 'The address could not be found';

    /**
     * @throws NumberParseException
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof PostalAddressConstraint) {
            throw new UnexpectedTypeException($constraint, PostalAddressConstraint::class);
        }
        if (!$value) {
            return;
        }
        if (!($value instanceof PostalAddress)) {
            throw new InternalErrorException(
                'Constraint of type PostalAddressConstraint can be applied only to properties of type PostalAddress'
            );
        }
        /** @var PostalAddress $address */
        $address = $value;
        if (!$address->isGeoCoded()) {
            $address->geocode();
        }
        try {
            if (isset($address->precision) && !in_array(
                    $address->precision,
                    PostalAddress::VALID_ADDRESS_PRECISION_LEVELS
                )) {
                // if address cannot be geocoded but customer selected geopoint is provided, we consider the address valid
                if ($customGeoPoint = $address->customerSelectedGeoPoint ?? null) {
                    return;
                }
                if ($address->precision == PostalAddress::PRECISIOIN_NOT_FOUND) {
                    $this->context->buildViolation(self::ADDRESS_NOT_FOUND)->addViolation();
                } else {
                    $this->context->buildViolation(self::ERROR_NO_ROOFTOP_PRECISION)->addViolation();
                }
            }
        } catch (Throwable) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}