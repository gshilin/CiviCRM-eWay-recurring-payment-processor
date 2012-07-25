<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM                                                            |
 +--------------------------------------------------------------------+
 | Copyright Henare Degan (C) 2012                                    |
 +--------------------------------------------------------------------+
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007.                                       |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License along with this program; if not, contact CiviCRM LLC       |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

// TODO: Remove hacky hardcoded constants
// The full path to your CiviCRM directory
define('CIVICRM_DIRECTORY', '/srv/www/localhost/wordpress/wp-content/plugins/civicrm/civicrm');
// The ID for contributions in a pending status
define('PENDING_CONTRIBUTION_STATUS_ID', 2);

// Initialise CiviCRM
chdir(CIVICRM_DIRECTORY);
require 'civicrm.config.php';
require 'CRM/Core/Config.php';
$config = CRM_Core_Config::singleton();

require_once 'api/api.php';
require_once 'CRM/Contribute/BAO/ContributionRecur.php';

/**
 * get_pending_recurring_contributions
 *
 * Gets recurring contributions that are in a pending state.
 * These are for newly created recurring contributions and should
 * generally be processed the same day they're created. These do not
 * include the regularly processed recurring transactions.
 *
 * @return array An array of associative arrays containing contribution arrays & contribtion_recur objects
 */
function get_pending_recurring_contributions()
{
    // Get pending contributions
    $params = array(
        'version' => 3,
        // TODO: Statuses are customisable so this configuration should be read from the DB
        'contribution_status_id' => PENDING_CONTRIBUTION_STATUS_ID
    );
    $pending_contributions = civicrm_api('contribution', 'get', $params);

    $result = array();

    foreach ($pending_contributions['values'] as $contribution) {
        // Only process those with recurring contribution records
        if ($contribution['contribution_recur_id']) {
            // Find the recurring contribution record for this contribution
            // TODO: Use the API when it has support for getting recurring contributions
            $recurring = new CRM_Contribute_BAO_ContributionRecur();
            $recurring->id = $contribution['contribution_recur_id'];

            // Only process records that have a recurring record with
            // a processor ID, i.e. an eWay token
            if ($recurring->find(true) && $recurring->processor_id) {
                // TODO: Return the same type of results
                // This is a bit nasty, contribution is an array and
                // contribution_recur is an object
                $result[] = array(
                    'contribution' => $contribution,
                    'contribution_recur' => $recurring
                );
            }
        }
    }
    return $result;
}

/**
 * eway_token_client
 *
 * Creates and eWay SOAP client to the eWay token API
 *
 * @param string $gateway_url URL of the gateway to connect to (could be the test or live gateway)
 * @param string $eway_customer_id Your eWay customer ID
 * @param string $username Your eWay business centre username
 * @param string $password Your eWay business centre password
 * @return object A SOAP client to the eWay token API
 */
function eway_token_client($gateway_url, $eway_customer_id, $username, $password)
{
    $soap_client = new SoapClient($gateway_url);

    // Set up SOAP headers
    $headers = array(
        'eWAYCustomerID' => $eway_customer_id,
        'Username'       => $username,
        'Password'       => $password
    );
    $header = new SoapHeader('https://www.eway.com.au/gateway/managedpayment', 'eWAYHeader', $headers);
    $soap_client->__setSoapHeaders($header);

    return $soap_client;
}

/**
 * process_eway_payment
 *
 * Processes an eWay token payment
 *
 * @param object $soap_client An eWay SOAP client set up and ready to go
 * @param string $managed_customer_id The eWay token ID for the credit card you want to process
 * @param string $amount_in_cents The amount in cents to charge the customer
 * @param string $invoice_reference InvoiceReference to send to eWay
 * @param string $invoice_description InvoiceDescription to send to eWay
 * @throws SoapFault exceptions
 * @return object eWay response object
 */
function process_eway_payment($soap_client, $managed_customer_id, $amount_in_cents, $invoice_reference, $invoice_description)
{
    $paymentinfo = array(
        'managedCustomerID' => $managed_customer_id,
        'amount' => $amount_in_cents,
        'InvoiceReference' => $invoice_reference,
        'InvoiceDescription' => $invoice_description
    );

    $result = $soap_client->ProcessPayment($paymentinfo);
    $eway_response = $result->ewayResponse;

    return $eway_response;
}