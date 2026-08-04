<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Event;

/**
 * Browser-side LiveComponent event names shared by the checkout components.
 */
final class CheckoutEvents
{
    public const UPDATE_ITEM_QUANTITY_EVENT = 'UPDATE_ITEM_QUANTITY_EVENT';
    public const ADD_ITEM_EVENT = 'CART_ADD_ITEM_EVENT';
    public const DELETE_ITEM_EVENT = 'CART_DELETE_ITEM_EVENT';
    public const SET_DELIVERY_MODULE_OPTION = 'SET_DELIVERY_MODULE_OPTION';
    public const SET_PAYMENT_MODULE_ID = 'SET_PAYMENT_MODULE_ID';
    public const SET_DELIVERY_ORDER_ADDRESS_ID = 'SET_DELIVERY_ORDER_ADDRESS_ID';
    public const SET_INVOICE_ORDER_ADDRESS_ID = 'SET_INVOICE_ORDER_ADDRESS_ID';
    public const ADD_NEW_DELIVERY_ADDRESS = 'ADD_NEW_DELIVERY_ADDRESS';
    public const EDIT_DELIVERY_ADDRESS = 'EDIT_DELIVERY_ADDRESS';
    public const EDIT_INVOICE_ADDRESS = 'EDIT_INVOICE_ADDRESS';
    public const REMOVE_INVOICE_ORDER_ADDRESS_ID = 'REMOVE_INVOICE_ORDER_ADDRESS_ID';
    public const DELETE_DELIVERY_ADDRESS = 'DELETE_DELIVERY_ADDRESS';
    public const ADD_PROMO_CODE = 'ADD_PROMO_CODE';
}
