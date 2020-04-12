<?php
/**
 * This file implements Subscription Builder.
 *
 * @author    Bilal Gultekin <bilal@gultekin.me>
 * @author    Justin Hartman <justin@22digital.co.za>
 * @copyright 2019 22 Digital
 * @license   MIT
 * @since     v0.1
 */

namespace TwentyTwoDigital\CashierFastspring;

use GuzzleHttp\Exception\ClientException;
use TwentyTwoDigital\CashierFastspring\Fastspring\Fastspring;

/**
 * Front-end to create subscription objects step by step.
 */
class SubscriptionBuilder
{
    /**
     * The model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $owner;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The quantity of the subscription.
     *
     * @var int
     */
    protected $quantity = 1;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * Predefined customer contact information.
     *
     * @var array|null
     */
    protected $contact;

    /**
     * Payload override
     *
     * @var array
     */
    protected $payload_override = [];

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $owner  Owner details
     * @param  string  $name  Plan name
     * @param  string  $plan  Plan
     *
     * @return void
     */
    public function __construct($owner, $name, $plan)
    {
        $this->name = $name;
        $this->plan = $plan;
        $this->owner = $owner;
    }

    /**
     * Specify the quantity of the subscription.
     *
     * @param  int  $quantity  Number of items
     *
     * @return $this
     */
    public function quantity($quantity)
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     *
     * @param  string  $coupon  Coupon string to use
     *
     * @return $this
     */
    public function withCoupon($coupon)
    {
        $this->coupon = $coupon;

        return $this;
    }

    /**
     * Predefined customer contact information being applied to a new subscription.
     *
     * @param  array  $contact  Contact information (email, firstName, lastName, company, phone)
     *
     * @return $this
     */
    public function withContact($contact)
    {
        $this->contact = $contact;

        return $this;
    }

    /**
     * Create a new Fastspring session and return it as object.
     *
     * @return \TwentyTwoDigital\CashierFastspring\Fastspring\Fastspring
     */
    public function create()
    {
        $fastspringId = $this->getFastspringIdOfCustomer();

        return Fastspring::createSession($this->buildPayload($fastspringId));
    }

    /**
     * Payload override
     *
     * @param $payload
     * @return $this
     */
    public function payload(array $payload)
    {
        $this->payload_override[] = $payload;
        return $this;
    }

    /**
     * Get the fastspring id for the current user.
     *
     * If an email key exists in error node then we assume this error is related
     * to the fact there is already an account with this email in
     * fastspring-side error message. It will also returns account link but
     * messages are easily changable so we can't rely on that.
     *
     * @return int|string
     * @throws Exception
     *
     */
    protected function getFastspringIdOfCustomer()
    {
        if (!$this->owner->fastspring_id) {
            try {
                $customer = $this->owner->createAsFastspringCustomer();
            } catch (ClientException $e) {
                // we should get its id and save it
                $response = $e->getResponse();
                $content = json_decode($response->getBody()->getContents());

                if (isset($content->error->email)) {
                    $response = Fastspring::getAccounts(['email' => $this->owner->email]);

                    if ($response->accounts) {
                        $account = $response->accounts[0];

                        // save it to eloquent model
                        $this->owner->fastspring_id = $account->id;
                        $this->owner->save();
                    }
                } else {
                    throw $e; // @codeCoverageIgnore
                }
            }
        }

        return $this->owner->fastspring_id;
    }

    /**
     * Build the payload for session creation.
     *
     * @param  int  $fastspringId  The fastspring identifier
     *
     * @return array
     */
    protected function buildPayload($fastspringId)
    {
        $payload_override = [
            'account' => $fastspringId,
            'items' => [
                [
                    'product' => $this->plan,
                    'quantity' => $this->quantity,
                ],
            ],
            'tags' => [
                'name' => $this->name,
            ],
            'coupon' => $this->coupon,
        ];

        if ($this->contact && is_array($this->contact)) {
            $payload_override['contact'] = $this->contact;
        }

        foreach ($this->payload_override as $payload_override) {
            $payload = array_filter(array_replace_recursive($payload, $payload_override));
        }

        return $data;
    }
}
