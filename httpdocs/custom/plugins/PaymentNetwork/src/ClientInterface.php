<?php


namespace P3\PaymentNetwork;

/**
 * Interface ClientInterface
 */
interface ClientInterface
{
    public function post(array $request);
}