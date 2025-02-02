<?php

declare(strict_types=1);

namespace AcVendor\Brick\Money\Context;

use AcVendor\Brick\Money\Context;
use AcVendor\Brick\Money\Currency;

use AcVendor\Brick\Math\BigDecimal;
use AcVendor\Brick\Math\BigNumber;

/**
 * Adjusts a number to the default scale for the currency.
 */
final class DefaultContext implements Context
{
    /**
     * @inheritdoc
     */
    public function applyTo(BigNumber $amount, Currency $currency, int $roundingMode) : BigDecimal
    {
        return $amount->toScale($currency->getDefaultFractionDigits(), $roundingMode);
    }

    /**
     * {@inheritdoc}
     */
    public function getStep() : int
    {
        return 1;
    }

    /**
     * {@inheritdoc}
     */
    public function isFixedScale() : bool
    {
        return true;
    }
}
