<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

/**
 * Runs a set of prepared curl handles concurrently through one `curl_multi`
 * handle and returns each handle's result array keyed by the caller's key
 * (Requirements 1.6, 5.1, 6.2).
 *
 * This helper is deliberately thin: the clients (RobotClient / RecorderClient)
 * own the per-handle configuration — URL, headers, body, and the per-call
 * `CURLOPT_TIMEOUT` of Requirement 1.7/1.11 — through their own `prepare()`.
 * ParallelHttp only fans the handles out through `curl_multi_init` /
 * `curl_multi_add_handle` / `curl_multi_exec`, waits for them all to finish,
 * and shapes each handle's outcome into the uniform result array the clients'
 * `finish()` consumes:
 *
 *   array{status:int, body:?string, seconds:float, error:?string}
 *
 * A handle that times out or otherwise fails yields a non-null `error`; its
 * `body` is then null and `status` is whatever curl reported (0 when the
 * transfer never completed). There is NO retry inside one request
 * (Requirements 1.9, 5.5): each handle is run exactly once.
 */
final class ParallelHttp
{
    /**
     * Execute every prepared handle concurrently and return their results.
     *
     * The handles are run through a single shared `curl_multi` handle, so both
     * (or all) calls are in flight at the same time (Requirement 1.6). The
     * returned map uses the same keys as the input map, so a caller can match
     * each result back to the client that prepared it.
     *
     * This method does not close the handles it is given; it removes them from
     * the multi handle and leaves closing to the caller, so the caller can read
     * additional `curl_getinfo` fields from its own handle if it wishes. The
     * internal `curl_multi` handle is always closed before returning.
     *
     * @param array<string,\CurlHandle> $handles Map of caller key => prepared handle.
     * @return array<string,array{status:int,body:?string,seconds:float,error:?string}>
     *         Map of the same keys => each handle's result array.
     */
    public function run(array $handles): array
    {
        if ($handles === []) {
            return [];
        }

        $multi = curl_multi_init();

        foreach ($handles as $handle) {
            curl_multi_add_handle($multi, $handle);
        }

        // Drive all transfers to completion. `curl_multi_exec` returns
        // CURLM_CALL_MULTI_PERFORM on older curls to ask to be called again;
        // we loop while that is signalled, then block on `curl_multi_select`
        // until there is activity, and stop once nothing is still running.
        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                // Wait (bounded) for activity so we do not spin the CPU; the
                // per-handle CURLOPT_TIMEOUT governs abandonment, not this.
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Collect per-handle curl error codes from the multi info queue, so a
        // timed-out or failed transfer can be reported as an `error`.
        $errorByHandleId = $this->collectHandleErrors($multi);

        $results = [];
        foreach ($handles as $key => $handle) {
            $results[$key] = $this->shapeResult($handle, $errorByHandleId);
            curl_multi_remove_handle($multi, $handle);
        }

        curl_multi_close($multi);

        return $results;
    }

    /**
     * Drain the `curl_multi` info queue, mapping each finished handle's object
     * id to its curl result code so failures can be detected per handle.
     *
     * @return array<int,int> Map of spl_object_id(handle) => CURLE_* result code.
     */
    private function collectHandleErrors(\CurlMultiHandle $multi): array
    {
        $errors = [];
        while (($info = curl_multi_info_read($multi)) !== false) {
            if ($info['msg'] === CURLMSG_DONE) {
                $handle = $info['handle'];
                if ($handle instanceof \CurlHandle) {
                    $errors[spl_object_id($handle)] = (int) $info['result'];
                }
            }
        }

        return $errors;
    }

    /**
     * Shape one finished handle into the uniform result array.
     *
     * @param array<int,int> $errorByHandleId
     * @return array{status:int,body:?string,seconds:float,error:?string}
     */
    private function shapeResult(\CurlHandle $handle, array $errorByHandleId): array
    {
        $resultCode = $errorByHandleId[spl_object_id($handle)] ?? CURLE_OK;

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $seconds = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);

        if ($resultCode !== CURLE_OK) {
            // A timed-out (CURLE_OPERATION_TIMEDOUT) or otherwise failed
            // transfer: report the error, with no body. `curl_error` may be
            // empty once the transfer is detached, so fall back to the code.
            $message = curl_error($handle);
            if ($message === '') {
                $message = 'curl error ' . $resultCode;
            }

            return [
                'status' => $status,
                'body' => null,
                'seconds' => $seconds,
                'error' => $message,
            ];
        }

        $body = curl_multi_getcontent($handle);

        return [
            'status' => $status,
            'body' => $body,
            'seconds' => $seconds,
            'error' => null,
        ];
    }
}
