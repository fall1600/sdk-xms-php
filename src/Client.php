<?php

/**
 * Contains the XMS client class.
 *
 * PHP versions 5 and 7
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License 2.0
 */

namespace Clx\Xms;

/**
 * Client used to communicate with the XMS server.
 *
 * This class will use the cURL functions to communicate with XMS. It
 * is intended as a long lived object and can handle multiple requests.
 *
 * For example, to send a simple parameterized text batch to three
 * recipients we may use code such as
 *
 * ```php
 * $client = new Clx\Xms\Client('{my-service-plan-id}', '{my-token}');
 *
 * try {
 *     $batchParams = new Clx\Xms\Api\MtBatchTextSmsCreate();
 *     $batchParams->sender = '12345';
 *     $batchParams->recipients = ['987654321', '123456789', '567894321'];
 *     $batchParams->body = 'Hello, ${name}!';
 *     $batchParams->parameters['name'] = [
 *         '987654321' => 'Mary',
 *         '123456789' => 'Joe',
 *         'default' => 'valued customer'
 *     ];
 *
 *     $batch = $client->createTextBatch($batchParams);
 *     echo('The batch was given ID ' . $batch->batchId);
 * } catch (Exception $ex) {
 *     echo('Error creating batch: ' . $ex->getMessage());
 * }
 * ```
 *
 * and to fetch a batch we may use the code (with `$client` being the
 * same variable as above)
 *
 * ```php
 * try {
 *     $batch = $client->fetchBatch('{a batch identifier}');
 *     echo('The batch was sent from ' . $batch->sender);
 * } catch (Exception $ex) {
 *     echo('Error fetching batch: ' . $ex->getMessage());
 * }
 * ```
 *
 * This client is aware of the PSR-3 logger interface and will log all
 * requests at the debug level if given a logger through the
 * {@link \Clx\Xms\Client::setLogger()} method.
 */
class Client implements \Psr\Log\LoggerAwareInterface
{

    /**
     * The default XMS endpoint.
     */
    const DEFAULT_ENDPOINT = "https://api.clxcommunications.com/xms";

    /**
     * The user agent string that is included in each request.
     *
     * We store it as an instance variable to avoid doing the
     * necessary string concatenation for each request.
     *
     * @var string the user agent string
     */
    private $_userAgent;

    /**
     * An initialized cURL handle.
     *
     * TODO: Consider making this variable static because PHP will
     * treat it as thread-local. As a result, this class would become
     * thread safe. But presumably could not have multiple instances
     * in one thread? Actually, that is probably not a problem since
     * the handle wouldn't be used simultaneously in the same thread.
     */
    private $_curlHandle;

    /**
     * The user service plan identifier.
     */
    private $_servicePlanId;

    /**
     * The user authentication token.
     */
    private $_token;

    /**
     * The base endpoint URL.
     */
    private $_endpoint;

    /**
     * @var \Psr\Log\LoggerInterface|null a logger
     */
    private $_logger;

    /**
     * Constructs a new XMS client.
     *
     * The constructed client will communicate with the given endpoint
     * using the given credentials. A default endpoint at CLX
     * Communications is used if no endpoint is explicitly provided.
     *
     * This client is _not_ thread-safe.
     *
     * @param string $service_plan_id the service plan identifier
     * @param string $token           the authentication token
     * @param string $endpoint        the XMS endpoint URL
     */
    public function __construct(
        $service_plan_id,
        $token,
        $endpoint = Client::DEFAULT_ENDPOINT
    ) {
        $this->_servicePlanId = $service_plan_id;
        $this->_token = $token;
        $this->_endpoint = $endpoint;
        $this->_userAgent = 'cURL/' . curl_version()['version']
                          . ' PHP/' . PHP_VERSION;

        if (!($this->_curlHandle = curl_init())) {
            throw new HttpCallException("failed to initialize cURL");
        }
    }

    /**
     * Destructs this client.
     *
     * This includes shutting down the internal HTTP client.
     */
    public function __destruct()
    {
        if ($this->_curlHandle) {
            curl_close($this->_curlHandle);
            $this->_curlHandle = null;
        }
    }

    /**
     * Assigns a PSR-3 logger to this client.
     *
     * The given logger will be used to log the content of each XMS
     * request and response.
     *
     * @param \Psr\Log\LoggerInterface $logger the logger to use
     *
     * @return void
     */
    public function setLogger(\Psr\Log\LoggerInterface $logger)
    {
        $this->_logger = $logger;
    }

    /**
     * Builds an endpoint URL for the given sub-path.
     *
     * @param string $sub_path the sub-path
     *
     * @return string an URL
     */
    private function _url($sub_path)
    {
        return $this->_endpoint . '/v1/' . $this->_servicePlanId . $sub_path;
    }

    /**
     * Builds an endpoint URL for the given batch and sub-path.
     *
     * @param string $batchId a batch identifier
     * @param string $subPath additional sub-path
     *
     * @return string a complete URL
     */
    private function _batchUrl($batchId, $subPath = '')
    {
        $ebid = rawurlencode($batchId);
        return $this->_url('/batches/' . $ebid . $subPath);
    }

    /**
     * Helper method that asks cURL to do an HTTP request.
     *
     * @param string      $url  URL that should receive the request
     * @param string|null $json request body, if needed
     *
     * @return string the request result body
     */
    private function _curlHelper(&$url, &$json = null)
    {
        $headers = [
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
            'Authorization: Bearer ' . $this->_token,
            'X-CLX-SDK-Version: ' . Version::version()
        ];

        /*
         * If this is a request that has a body then we need to
         * include the content type, which in our case always is JSON.
         */
        if (isset($json)) {
            array_push($headers, 'Content-Type: application/json');
            curl_setopt($this->_curlHandle, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($this->_curlHandle, CURLOPT_URL, $url);
        curl_setopt($this->_curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->_curlHandle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($this->_curlHandle, CURLOPT_USERAGENT, $this->_userAgent);

        $result = curl_exec($this->_curlHandle);

        if ($result === false) {
            throw new HttpCallException(curl_error($this->_curlHandle));
        }

        $httpStatus = curl_getinfo($this->_curlHandle, CURLINFO_HTTP_CODE);

        // If we have a logger then we can emit a bit of debug info.
        if (isset($this->_logger)) {
            $httpTime = curl_getinfo($this->_curlHandle, CURLINFO_TOTAL_TIME);
            $this->_logger->debug(
                'Request: {req}; Response (status {status}, took {time}s): {rsp}',
                [
                    'req' => $json,
                    'rsp' => $result,
                    'status' => $httpStatus,
                    'time' => $httpTime
                ]
            );
        }

        switch ($httpStatus) {
        case 200:               // OK
        case 201:               // Created
            break;
        case 400:               // Bad Request
        case 403:               // Forbidden
            $e = Deserialize::error($result);
            throw new XmsErrorException($e->code, $e->text);
        case 404:               // Not Found
            throw new NotFoundException($url);
        case 401:               // Unauthorized
            throw new UnauthorizedException(
                $this->_servicePlanId, $this->_token
            );
        default:                // Everything else
            throw new UnexpectedResponseException(
                "Unexpected HTTP status $httpStatus", $result
            );
        }

        return $result;
    }

    /**
     * Helper that performs a HTTP GET operation.
     *
     * @param string $url the URL to GET
     *
     * @return string the response
     */
    private function _get($url)
    {
        return $this->_curlHelper($url);
    }

    /**
     * Helper that performs a HTTP DELETE operation.
     *
     * @param string $url the URL to DELETE
     *
     * @return string the response (typically empty)
     */
    private function _delete($url)
    {
        curl_setopt($this->_curlHandle, CURLOPT_CUSTOMREQUEST, 'DELETE');
        return $this->_curlHelper($url);
    }

    /**
     * Helper that performs a HTTP POST operation.
     *
     * @param string $url  the URL to POST to
     * @param string $json the JSON payload
     *
     * @return string the response
     */
    private function _post($url, &$json)
    {
        curl_setopt($this->_curlHandle, CURLOPT_POST, true);
        return $this->_curlHelper($url, $json);
    }

    /**
     * Helper that performs a HTTP PUT operation.
     *
     * @param string $url  the URL to PUT to
     * @param string $json the JSON payload
     *
     * @return string the response
     */
    private function _put($url, &$json)
    {
        curl_setopt($this->_curlHandle, CURLOPT_CUSTOMREQUEST, 'PUT');
        return $this->_curlHelper($url, $json);
    }

    /**
     * Creates a new text batch.
     *
     * The text batch will be created as described in the given
     * object.
     *
     * @param Api\MtBatchTextSmsCreate $batch the batch description
     *
     * @return Api\MtBatchTextSmsResult the creation result
     */
    public function createTextBatch(Api\MtBatchTextSmsCreate $batch)
    {
        $json = Serialize::textBatch($batch);
        $result = $this->_post($this->_url('/batches'), $json);
        return Deserialize::batchResponse($result);
    }

    /**
     * Creates a new binary batch.
     *
     * The binary batch will be created as described in the given
     * object.
     *
     * @param Api\MtBatchBinarySmsCreate $batch the batch description
     *
     * @return Api\MtBatchBinarySmsResult the creation result
     */
    public function createBinaryBatch(Api\MtBatchBinarySmsCreate $batch)
    {
        $json = Serialize::binaryBatch($batch);
        $result = $this->_post($this->_url('/batches'), $json);
        return Deserialize::batchResponse($result);
    }

    /**
     * Simulates sending the given batch.
     *
     * The method takes an optional argument for instructing XMS to
     * respond with per-recipient statistics, if non-null then this
     * number of recipients will be returned in the result.
     *
     * @param Api\MtBatchSmsCreate $batch         the batch to simulate
     * @param int|null             $numRecipients number of recipients
     *     to show in per-recipient result
     *
     * @return Api\MtBatchDryRunResult result of dry-run
     */
    public function createBatchDryRun(
        Api\MtBatchSmsCreate $batch, $numRecipients = null
    ) {
        if ($batch instanceof Api\MtBatchTextSmsCreate) {
            $json = Serialize::textBatch($batch);
        } else if ($batch instanceof Api\MtBatchBinarySmsCreate) {
            $json = Serialize::binaryBatch($batch);
        } else {
            throw new \InvalidArgumentException(
                'Expected text or binary batch'
            );
        }

        $path = '/batches/dry_run';

        if (isset($numRecipients)) {
            $path .= "?per_recipient=true&number_of_recipients=$numRecipients";
        }

        $result = $this->_post($this->_url($path), $json);
        return Deserialize::batchDryRun($result);
    }

    /**
     * Replaces the batch with the given ID with the given text batch.
     *
     * @param string                   $batchId identifier of the batch
     * @param Api\MtBatchTextSmsCreate $batch   the replacement batch
     *
     * @return Api\MtBatchTextSmsResult the resulting batch
     */
    public function replaceTextBatch(
        $batchId, Api\MtBatchTextSmsCreate $batch
    ) {
        $json = Serialize::textBatch($batch);
        $result = $this->_put($this->_batchUrl($batchId), $json);
        return Deserialize::batchResponse($result);
    }

    /**
     * Replaces the batch with the given ID with the given binary
     * batch.
     *
     * @param string                     $batchId identifier of the batch
     * @param Api\MtBatchBinarySmsCreate $batch   the replacement batch
     *
     * @return Api\MtBatchBinarySmsResult the resulting batch
     */
    public function replaceBinaryBatch(
        $batchId, Api\MtBatchBinarySmsCreate $batch
    ) {
        $json = Serialize::binaryBatch($batch);
        $result = $this->_put($this->_batchUrl($batchId), $json);
        return Deserialize::batchResponse($result);
    }

    /**
     * Updates the text batch with the given identifier.
     *
     * @param string                   $batchId identifier of the batch
     * @param Api\MtBatchTextSmsUpdate $batch   the update description
     *
     * @return Api\MtBatchTextSmsResult the updated batch
     */
    public function updateTextBatch(
        $batchId, Api\MtBatchTextSmsUpdate $batch
    ) {
        $json = Serialize::textBatchUpdate($batch);
        $result = $this->_post($this->_batchUrl($batchId), $json);
        return Deserialize::batchResponse($result);
    }

    /**
     * Updates the binary batch with the given identifier.
     *
     * @param string                     $batchId identifier of the batch
     * @param Api\MtBatchBinarySmsUpdate $batch   the update description
     *
     * @return Api\MtBatchBinarySmsResult the updated batch
     */
    public function updateBinaryBatch(
        $batchId, Api\MtBatchBinarySmsUpdate $batch
    ) {
        $json = Serialize::binaryBatchUpdate($batch);
        $result = $this->_post($this->_batchUrl($batchId), $json);
        return Deserialize::batchResponse($result);
    }

    /**
     * Cancels the batch with the given batch identifier.
     *
     * @param string $batchId the batch identifier
     *
     * @return void
     */
    public function cancelBatch($batchId)
    {
        $this->_delete($this->_batchUrl($batchId));
    }

    /**
     * Replaces the tags of the given batch.
     *
     * @param string   $batchId identifier of the batch
     * @param string[] $tags    the new set of batch tags
     *
     * @return string[] the new batch tags
     */
    public function replaceBatchTags($batchId, array $tags)
    {
        $json = Serialize::tags($tags);
        $result = $this->_put($this->_batchUrl($batchId, '/tags'), $json);
        return Deserialize::tags($result);
    }

    /**
     * Updates the tags of the given batch.
     *
     * @param string   $batchId      batch identifier
     * @param string[] $tagsToAdd    tags to add to batch
     * @param string[] $tagsToRemove tags to remove from batch
     *
     * @return string[] the updated batch tags
     */
    public function updateBatchTags(
        $batchId, array $tagsToAdd, array $tagsToRemove
    ) {
        $json = Serialize::tagsUpdate($tagsToAdd, $tagsToRemove);
        $result = $this->_post($this->_batchUrl($batchId, '/tags'), $json);
        return Deserialize::tags($result);
    }

    /**
     * Fetches the batch with the given batch identifier.
     *
     * @param string $batchId batch identifier
     *
     * @return Api\MtSmsBatchResponse the corresponding batch
     */
    public function fetchBatch($batchId)
    {
        $result = $this->_get($this->_batchUrl($batchId));
        return Deserialize::batchResponse($result);
    }

    /**
     * Fetch the batches matching the given filter.
     *
     * Note, calling this method does not actually cause any network
     * traffic. Listing batches in XMS may return the result over
     * multiple pages and this call therefore returns an object of the
     * type {@link \Clx\Xms\Api\Pages}, which will fetch result pages
     * as needed.
     *
     * @param BatchFilter|null $filter the batch filter
     *
     * @return Api\Pages the result pages
     */
    public function fetchBatches(BatchFilter $filter = null)
    {
        return new Api\Pages(
            function ($page) use ($filter) {
                $params = ["page=$page"];

                if (!is_null($filter)) {
                    if (isset($filter->pageSize)) {
                        array_push($params, 'page_size=' . $filter->pageSize);
                    }

                    if (isset($filter->senders)) {
                        $val = urlencode(join(',', $filter->senders));
                        array_push($params, 'from=' . $val);
                    }

                    if (isset($filter->tags)) {
                        $val = urlencode(join(',', $filter->tags));
                        array_push($params, 'tags=' . $val);
                    }

                    if (isset($filter->startDate)) {
                        $val = $filter->startDate->format('Y-m-d');
                        array_push($params, 'start_date=' . $val);
                    }

                    if (isset($filter->endDate)) {
                        $val = $filter->endDate->format('Y-m-d');
                        array_push($params, 'end_date=' . $val);
                    }
                }

                $q = join('&', $params);
                $result = $this->_get($this->_url('/batches?' . $q));
                return Deserialize::batchesPage($result);
            }
        );
    }

    /**
     * Fetches the tags associated with the given batch.
     *
     * @param string $batchId the batch identifier
     *
     * @return string[] a list of tags
     */
    public function fetchBatchTags($batchId)
    {
        $result = $this->_get($this->_batchUrl($batchId, '/tags'));
        return Deserialize::tags($result);
    }

    /**
     * Fetches a delivery report for a batch.
     *
     * The report type can be either
     * {@link Clx\Xms\DeliveryReportType::FULL "full"}
     * or
     * {@link Clx\Xms\DeliveryReportType::SUMMARY "summary"}
     * and when "full" the report includes the individual recipients.
     *
     * The report can be further limited by status and code. For
     * example, to retrieve a summary report limited to messages
     * having delivery status "Delivered" or "Failed" and codes "0",
     * "11", or "400", one could call
     *
     * ```php
     * $conn->fetchDeliveryReport(
     *     'MyBatchId',
     *     Clx\Xms\DeliveryReportType::SUMMARY,
     *     ['Delivered', 'Failed'],
     *     [0, 11, 400]
     * );
     * ```
     *
     * If the non-identifier parameters are left as `null` then the
     * XMS defaults are used. In particular, all statuses and codes
     * are included in the report.
     *
     * @param string        $batchId identifier of the batch
     * @param string|null   $type    delivery report type
     * @param string[]|null $status  statuses to fetch
     * @param int[]|null    $code    codes to fetch
     *
     * @return Api\DeliveryReport the batch delivery report
     */
    public function fetchDeliveryReport(
        $batchId,
        $type = null,
        array $status = null,
        array $code = null
    ) {
        $params = [];

        if (isset($type)) {
            array_push($params, 'type=' . $type);
        }

        if (!empty($status)) {
            $val = urlencode(join(',', $status));
            array_push($params, 'status=' . $val);
        }

        if (!empty($code)) {
            $val = urlencode(join(',', $code));
            array_push($params, 'code=' . $val);
        }

        $path = '/delivery_report';

        if (!empty($params)) {
            $path .= '?' . join('&', $params);
        }

        $result = $this->_get($this->_batchUrl($batchId, $path));
        return Deserialize::batchDeliveryReport($result);
    }

    /**
     * Fetches a delivery report for a specific batch recipient.
     *
     * @param string $batchId   the batch identifier
     * @param string $recipient the batch recipient
     *
      * @return Api\BatchRecipientDeliveryReport the delivery report
     */
    public function fetchRecipientDeliveryReport($batchId, $recipient)
    {
        $path = '/delivery_report/' . urlencode($recipient);
        $result = $this->_get($this->_batchUrl($batchId, $path));
        return Deserialize::batchRecipientDeliveryReport($result);
    }

    /**
     * Creates the given group.
     *
     * @param Api\GroupCreate $group group description
     *
     * @return Api\GroupResponse the created group
     */
    public function createGroup(Api\GroupCreate $group)
    {
        $json = Serialize::group($group);
        $result = $this->_post($this->_url('/groups'), $json);
        return Deserialize::groupResponse($result);
    }

    /**
     * Replaces the tags of the given group.
     *
     * @param string   $groupId identifier of the group
     * @param string[] $tags    the new set of group tags
     *
     * @return string[] the new group tags
     */
    public function replaceGroupTags($groupId, array $tags)
    {
        $json = Serialize::tags($tags);
        $result = $this->_put($this->_url("/groups/$groupId/tags"), $json);
        return Deserialize::tags($result);
    }

    /**
     * Updates the group with the given identifier.
     *
     * @param string          $groupId identifier of the group
     * @param Api\GroupUpdate $group   the update description
     *
     * @return Api\GroupResponse the updated batch
     */
    public function updateGroup($groupId, Api\GroupUpdate $group)
    {
        $json = Serialize::groupUpdate($group);
        $result = $this->_post($this->_url("/groups/$groupId"), $json);
        return Deserialize::groupResponse($result);
    }

    /**
     * Updates the tags of the given group.
     *
     * @param string   $groupId      group identifier
     * @param string[] $tagsToAdd    tags to add to group
     * @param string[] $tagsToRemove tags to remove from group
     *
     * @return string[] the updated group tags
     */
    public function updateGroupTags(
        $groupId, array $tagsToAdd, array $tagsToRemove
    ) {
        $json = Serialize::tagsUpdate($tagsToAdd, $tagsToRemove);
        $result = $this->_post($this->_url("/groups/$groupId/tags"), $json);
        return Deserialize::tags($result);
    }

    /**
     * Deletes the group with the given group identifier.
     *
     * @param string $groupId the group identifier
     *
     * @return void
     */
    public function deleteGroup($groupId)
    {
        $this->_delete($this->_url("/groups/$groupId"));
    }

    /**
     * Fetches the group with the given group identifier.
     *
     * @param string $groupId group identifier
     *
     * @return Api\GroupResponse the corresponding group
     */
    public function fetchGroup($groupId)
    {
        $result = $this->_get($this->_url('/groups/' . $groupId));
        return Deserialize::groupResponse($result);
    }

    /**
     * Fetch the groups matching the given filter.
     *
     * Note, calling this method does not actually cause any network
     * traffic. Listing groups in XMS may return the result over
     * multiple pages and this call therefore returns an object of the
     * type {@link \Clx\Xms\Api\Pages}, which will fetch result pages
     * as needed.
     *
     * @param GroupFilter|null $filter the group filter
     *
     * @return Api\Pages the result pages
     */
    public function fetchGroups(GroupFilter $filter = null)
    {
        return new Api\Pages(
            function ($page) use ($filter) {
                $params = ["page=$page"];

                if (!is_null($filter)) {
                    if (isset($filter->pageSize)) {
                        array_push($params, 'page_size=' . $filter->pageSize);
                    }

                    if (isset($filter->tags)) {
                        $val = urlencode(join(',', $filter->tags));
                        array_push($params, 'tags=' . $val);
                    }
                }

                $q = join('&', $params);
                $result = $this->_get($this->_url('/groups?' . $q));
                return Deserialize::groupsPage($result);
            }
        );
    }

    /**
     * Fetches the tags associated with the given group.
     *
     * @param string $groupId the group identifier
     *
     * @return string[] a list of tags
     */
    public function fetchGroupTags($groupId)
    {
        $result = $this->_get($this->_url("/groups/$groupId/tags"));
        return Deserialize::tags($result);
    }

    /**
     * Fetches the inbound message with the given identifier.
     *
     * The returned message is either
     * {@link Clx\Xms\Api\MoTextSms textual} or
     * {@link Clx\Xms\Api\MoBinarySms binary}.
     *
     * @param string $inboundId message identifier
     *
     * @return Api\MoSms the fetched message
     */
    public function fetchInbound($inboundId)
    {
        $result = $this->_get($this->_url("/inbounds/$inboundId"));
        return Deserialize::moSms($result);
    }

    /**
     * Fetch inbound messages matching the given filter.
     *
     * Note, calling this method does not actually cause any network
     * traffic. Listing inbound messages in XMS may return the result
     * over multiple pages and this call therefore returns an object
     * of the type {@link \Clx\Xms\Api\Pages}, which will fetch result
     * pages as needed.
     *
     * @param InboundsFilter|null $filter the inbound message filter
     *
     * @return Api\Pages the result pages
     */
    public function fetchInbounds(InboundsFilter $filter = null)
    {
        return new Api\Pages(
            function ($page) use ($filter) {
                $params = ["page=$page"];

                if (!is_null($filter)) {
                    if (isset($filter->pageSize)) {
                        array_push($params, 'page_size=' . $filter->pageSize);
                    }

                    if (isset($filter->recipients)) {
                        $val = urlencode(join(',', $filter->recipients));
                        array_push($params, 'to=' . $val);
                    }

                    if (isset($filter->startDate)) {
                        $val = $filter->startDate->format('Y-m-d');
                        array_push($params, 'start_date=' . $val);
                    }

                    if (isset($filter->endDate)) {
                        $val = $filter->endDate->format('Y-m-d');
                        array_push($params, 'end_date=' . $val);
                    }
                }

                $q = join('&', $params);
                $result = $this->_get($this->_url('/inbounds?' . $q));
                return Deserialize::inboundsPage($result);
            }
        );
    }

}

?>
