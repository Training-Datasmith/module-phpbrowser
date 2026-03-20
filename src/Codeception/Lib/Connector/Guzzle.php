<?php

declare (strict_types=1);
namespace Codeception\Lib\Connector;

use Aws\Credentials\Credentials as AwsCredentials;
use Aws\Signature\Signature_V4 as AwsSignatureV4;
use Codeception\Util\Uri;
use Guzzle_Http\Client as GuzzleClient;
use Guzzle_Http\Cookie\Cookie_Jar as GuzzleCookieJar;
use Guzzle_Http\Cookie\Set_Cookie;
use Guzzle_Http\Exception\Request_Exception;
use Guzzle_Http\Handler\Curl_Handler;
use Guzzle_Http\Handler\Stream_Handler;
use Guzzle_Http\Handler_Stack as GuzzleHandlerStack;
use Guzzle_Http\Psr7\Request as Psr7Request;
use Guzzle_Http\Psr7\Response as Psr7Response;
use Guzzle_Http\Psr7\Uri as Psr7Uri;
use Symfony\Component\Browser_Kit\Abstract_Browser;
use Symfony\Component\Browser_Kit\Request as BrowserKitRequest;
use Symfony\Component\Browser_Kit\Response as BrowserKitResponse;
class Guzzle extends Abstract_Browser
{
    /**
     * @var array<string, mixed>
     */
    protected array $request_options = ['allow_redirects' => false, 'headers' => []];
    protected int $refresh_max_interval = 0;
    protected ?Aws_Credentials $aws_credentials = null;
    protected ?Aws_Signature_V4 $aws_signature = null;
    protected ?Guzzle_Client $client = null;
    /**
     * Sets the maximum allowable timeout interval for a meta tag refresh to
     * automatically redirect a request.
     *
     * A meta tag detected with an interval equal to or greater than $seconds
     * would not result in a redirect.  A meta tag without a specified interval
     * or one with a value less than $seconds would result in the client
     * automatically redirecting to the specified URL
     *
     * @param int $seconds Number of seconds
     */
    public function set_refresh_max_interval(int $seconds): void
    {
        $this->refresh_max_interval = $seconds;
    }
    public function set_client(Guzzle_Client $guzzle_client): void
    {
        $this->client = $guzzle_client;
    }
    /**
     * Sets the request header to the passed value.  The header will be
     * sent along with the next request.
     *
     * Passing an empty value clears the header, which is the equivalent
     * of calling deleteHeader.
     *
     * @param string $name the name of the header
     * @param string $value the value of the header
     */
    public function set_header(string $name, string $value): void
    {
        if ($value === '') {
            $this->delete_header($name);
        } else {
            $this->request_options['headers'][$name] = $value;
        }
    }
    /**
     * Deletes the header with the passed name from the list of headers
     * that will be sent with the request.
     *
     * @param string $name the name of the header to delete.
     */
    public function delete_header(string $name): void
    {
        unset($this->request_options['headers'][$name]);
    }
    public function set_auth(string $username, string $password, string $type = 'basic'): void
    {
        if ($username === '') {
            unset($this->request_options['auth']);
            return;
        }
        $this->request_options['auth'] = [$username, $password, $type];
    }
    /**
     * Taken from Mink\BrowserKitDriver
     */
    protected function create_response(Psr7Response $psr7Response): Browser_Kit_Response
    {
        $body = (string) $psr7Response->get_body();
        $headers = $psr7Response->get_headers();
        $content_type = null;
        if (isset($headers['Content-Type'])) {
            $content_type = reset($headers['Content-Type']);
        }
        if (!$content_type) {
            $content_type = 'text/html';
        }
        if (str_contains((string) $content_type, 'charset=') === false) {
            if (preg_match('#<meta[^>]+charset *= *["\']?([a-zA-Z\-0-9]+)#i', $body, $matches)) {
                $content_type .= ';charset=' . $matches[1];
            }
            $headers['Content-Type'] = [$content_type];
        }
        $status = $psr7Response->get_status_code();
        if ($status < 300 || $status >= 400) {
            $matches = [];
            $matches_meta = preg_match('#<meta[^>]+http-equiv="refresh" content="\s*(\d*)\s*;\s*url=(.*?)"#i', $body, $matches);
            if (!$matches_meta && isset($headers['Refresh'])) {
                // match by header
                preg_match('#^\s*(\d*)\s*;\s*url=(.*)#i', (string) reset($headers['Refresh']), $matches);
            }
            if (!empty($matches) && (empty($matches[1]) || $matches[1] < $this->refresh_max_interval)) {
                $uri = new Psr7Uri($this->get_absolute_uri($matches[2]));
                $current_uri = new Psr7Uri($this->get_history()->current()->get_uri());
                if ($uri->with_fragment('') !== $current_uri->with_fragment('')) {
                    $status = 302;
                    $headers['Location'] = $matches_meta ? htmlspecialchars_decode((string) $uri) : (string) $uri;
                }
            }
        }
        return new Browser_Kit_Response($body, $status, $headers);
    }
    protected function get_absolute_uri(string $uri): string
    {
        $base_uri = $this->client->get_config('base_uri');
        if (str_contains($uri, '://') === false && !str_starts_with($uri, '//')) {
            if (str_starts_with($uri, '/')) {
                $base_uri_path = $base_uri->get_path();
                if (!empty($base_uri_path) && str_starts_with($uri, (string) $base_uri_path)) {
                    $uri = substr($uri, strlen((string) $base_uri_path));
                }
                return Uri::append_path((string) $base_uri, $uri);
            }
            // relative url
            if (!$this->get_history()->is_empty()) {
                return Uri::merge_urls($this->get_history()->current()->get_uri(), $uri);
            }
        }
        return Uri::merge_urls((string) $base_uri, $uri);
    }
    protected function do_request(object $request): object
    {
        /** @var BrowserKitRequest $request **/
        $guzzle_request = new Psr7Request($request->get_method(), $request->get_uri(), $this->extract_headers($request), $request->get_content());
        $options = $this->request_options;
        $options['cookies'] = $this->extract_cookies($guzzle_request->get_uri()->get_host());
        $multipart_data = $this->extract_multipart_form_data($request);
        if ($multipart_data !== []) {
            $options['multipart'] = $multipart_data;
        }
        $form_data = $this->extract_form_data($request);
        if ($multipart_data === [] && $form_data) {
            $options['form_params'] = $form_data;
        }
        try {
            if ($this->aws_credentials instanceof Aws_Credentials) {
                $response = $this->client->send($this->aws_signature->sign_request($guzzle_request, $this->aws_credentials), $options);
            } else {
                $response = $this->client->send($guzzle_request, $options);
            }
        } catch (Request_Exception $exception) {
            if (!$exception->has_response()) {
                throw $exception;
            }
            $response = $exception->get_response();
        }
        // @phpstan-ignore-next-line
        return $this->create_response($response);
    }
    /**
     * @return array<string, mixed>
     */
    protected function extract_headers(Browser_Kit_Request $request): array
    {
        $headers = [];
        $server = $request->get_server();
        $content_headers = ['Content-Length' => true, 'Content-Md5' => true, 'Content-Type' => true];
        foreach ($server as $header => $val) {
            $header = html_entity_decode(implode('-', array_map(ucfirst(...), explode('-', strtolower(str_replace('_', '-', $header))))), ENT_NOQUOTES);
            if (str_starts_with($header, 'Http-')) {
                $headers[substr($header, 5)] = $val;
            } elseif (isset($content_headers[$header])) {
                $headers[$header] = $val;
            }
        }
        return $headers;
    }
    /**
     * @return array<int, mixed>|null
     */
    protected function extract_form_data(Browser_Kit_Request $browser_kit_request): ?array
    {
        if (!in_array(strtoupper($browser_kit_request->get_method()), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return null;
        }
        // guessing if it is a form data
        $headers = $browser_kit_request->get_server();
        // not a form
        if (isset($headers['HTTP_CONTENT_TYPE']) && $headers['HTTP_CONTENT_TYPE'] !== 'application/x-www-form-urlencoded') {
            return null;
        }
        if ($browser_kit_request->get_content() !== null) {
            return null;
        }
        return $browser_kit_request->get_parameters();
    }
    /**
     * @return array<string, mixed>
     */
    protected function extract_multipart_form_data(Browser_Kit_Request $browser_kit_request): array
    {
        if (!in_array(strtoupper($browser_kit_request->get_method()), ['POST', 'PUT', 'PATCH'])) {
            return [];
        }
        $parts = $this->map_files($browser_kit_request->get_files());
        if ($parts === []) {
            return [];
        }
        foreach ($browser_kit_request->get_parameters() as $k => $parameter) {
            $parts = $this->format_multipart($parts, $k, $parameter);
        }
        return $parts;
    }
    /**
     * @return array<string, mixed>
     */
    protected function format_multipart(mixed $parts, string $key, mixed $value): array
    {
        if (is_array($value)) {
            foreach ($value as $sub_key => $sub_value) {
                $parts = array_merge($parts, $this->format_multipart([], $key . sprintf('[%s]', $sub_key), $sub_value));
            }
            return $parts;
        }
        $parts[] = ['name' => $key, 'contents' => (string) $value];
        return $parts;
    }
    /**
     * @param array<int, mixed> $requestFiles
     * @return array<int, mixed>
     */
    protected function map_files(array $request_files, ?string $array_name = ''): array
    {
        $files = [];
        foreach ($request_files as $name => $info) {
            if (!empty($array_name)) {
                $name = $array_name . '[' . $name . ']';
            }
            if (is_array($info)) {
                if (isset($info['tmp_name'])) {
                    if ($info['tmp_name']) {
                        $handle = fopen($info['tmp_name'], 'rb');
                        $filename = $info['name'] ?? null;
                        $file = ['name' => $name, 'contents' => $handle, 'filename' => $filename];
                        if (isset($info['type'])) {
                            $file['headers'] = ['content-type' => $info['type']];
                        }
                        $files[] = $file;
                    }
                } else {
                    $files = array_merge($files, $this->map_files($info, $name));
                }
            } else {
                $files[] = ['name' => $name, 'contents' => fopen($info, 'rb')];
            }
        }
        return $files;
    }
    protected function extract_cookies(string $host): Guzzle_Cookie_Jar
    {
        $jar = [];
        $cookies = $this->get_cookie_jar()->all();
        foreach ($cookies as $cookie) {
            $set_cookie = Set_Cookie::from_string((string) $cookie);
            if (!$set_cookie->get_domain()) {
                $set_cookie->set_domain($host);
            }
            $jar[] = $set_cookie;
        }
        return new Guzzle_Cookie_Jar(false, $jar);
    }
    public static function create_handler(mixed $handler): Guzzle_Handler_Stack
    {
        if ($handler instanceof Guzzle_Handler_Stack) {
            return $handler;
        }
        if ($handler === 'curl') {
            return Guzzle_Handler_Stack::create(new Curl_Handler());
        }
        if ($handler === 'stream') {
            return Guzzle_Handler_Stack::create(new Stream_Handler());
        }
        if (is_string($handler) && class_exists($handler)) {
            return Guzzle_Handler_Stack::create(new $handler());
        }
        if (is_callable($handler)) {
            return Guzzle_Handler_Stack::create($handler);
        }
        return Guzzle_Handler_Stack::create();
    }
    /**
     * @param array<string, mixed> $config
     */
    public function set_aws_auth(array $config): void
    {
        $this->aws_credentials = new Aws_Credentials($config['key'], $config['secret']);
        $this->aws_signature = new Aws_Signature_V4($config['service'], $config['region']);
    }
}