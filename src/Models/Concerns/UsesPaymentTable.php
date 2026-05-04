<?php

namespace Balerka\LaravelReactPayments\Models\Concerns;

trait UsesPaymentTable
{
    public function getTable(): string
    {
        return (string) config("payments.tables.{$this->paymentTableKey}", parent::getTable());
    }
}
