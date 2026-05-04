<?php

namespace Balerka\LaravelPayhub\Models\Concerns;

trait UsesPaymentTable
{
    public function getTable(): string
    {
        return (string) config("payhub.tables.{$this->paymentTableKey}", parent::getTable());
    }
}
