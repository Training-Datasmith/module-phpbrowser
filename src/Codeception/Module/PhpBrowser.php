<?php

declare (strict_types=1);
namespace Codeception\Module;

use Closure;
use Codeception\Lib\Connector\Guzzle;
use Codeception\Lib\Inner_Browser;
use Codeception\Lib\Interfaces\Multi_Session;
use Codeception\Lib\Interfaces\Remote;
use Codeception\Test_Interface;
use Codeception\Util\Uri;
use Guzzle_Http\Client as GuzzleClient;
use Symfony\Component\Browser_Kit\Abstract_Browser;
/**
 * Uses [Guzzle](https://docs.guzzlephp.org/en/stable/) to interact with your application over CURL.
 * Module works over CURL and requires **PHP CURL extension** to be enabled.
 *
 * Use to perform web acceptance tests with non-javascript browser.
 *
 * If test fails stores last shown page in 'output' dir.
 *
 * ## Configuration
 *
 * * url *required* - start url of your app
 * * headers - default headers are set before each test.
 * * handler (default: curl) -  Guzzle handler to use. By default curl is used, also possible to pass `stream`, or any valid class name as [Handler](https://docs.guzzlephp.org/en/latest/handlers-and-middleware.html#handlers).
 * * middleware - Guzzle middlewares to add. An array of valid callables is required.
 * * curl - curl options
 * * cookies - ...
 * * auth - ...
 * * verify - ...
 * * .. those and other [Guzzle Request options](https://docs.guzzlephp.org/en/latest/request-options.html)
 *
 *
 * ### Example (`Acceptance.suite.yml`)
 *
 * ```yaml
 * modules:
 *    enabled:
 *        - PhpBrowser:
 *            url: 'http://localhost' # Internationalized domain names (IDN) need to be passed in punycode
 *            auth: ['admin', '123345']
 *            curl:
 *                CURLOPT_RETURNTRANSFER: true
 *            cookies:
 *                cookie-1:
 *                    Name: userName
 *                    Value: john.doe
 *                cookie-2:
 *                    Name: authToken
 *                    Value: 1abcd2345
 *                    Domain: subdomain.domain.com
 *                    Path: /admin/
 *                    Expires: 1292177455
 *                    Secure: true
 *                    HttpOnly: false
 * ```
 *
 * All SSL certification checks are disabled by default.
 * Use Guzzle request options to configure certifications and others.
 *
 * ## Public API
 *
 * Those properties and methods are expected to be used in Helper classes:
 *
 * Properties:
 *
 * * `guzzle` - contains [Guzzle](https://guzzlephp.org/) client instance: `\GuzzleHttp\Client`
 * * `client` - Symfony BrowserKit instance.
 *
 */
class Php_Browser extends Inner_Browser implements Remote, Multi_Session
{
    /**
     * @var string[]
     */
    protected array $required_fields = ['url'];
    /**
     * @var array<string, mixed>
     */
    protected array $config = [
        'headers' => [],
        'verify' => false,
        'expect' => false,
        'timeout' => 30,
        'curl' => [],
        'refresh_max_interval' => 10,
        'handler' => 'curl',
        'middleware' => null,
        // required defaults (not recommended to change)
        'allow_redirects' => false,
        'http_errors' => false,
        'cookies' => true,
    ];
    /**
     * @var string[]
     */
    protected array $guzzle_config_fields = ['auth', 'proxy', 'verify', 'cert', 'query', 'ssl_key', 'proxy', 'expect', 'version', 'timeout', 'connect_timeout'];
    public ?Abstract_Browser $client = null;
    public ?Guzzle_Client $guzzle = null;
    public function _initialize(): void
    {
        $this->_initialize_session();
    }
    public function _before(Test_Interface $test): void
    {
        if (!$this->client instanceof Abstract_Browser) {
            $this->client = new Guzzle();
        }
        $this->_prepare_session();
    }
    public function _get_url()
    {
        return $this->config['url'];
    }
    /**
     * Alias to `haveHttpHeader`
     */
    public function set_header(string $name, string $value): void
    {
        $this->have_http_header($name, $value);
    }
    public function am_http_authenticated(string $username, string $password): void
    {
        if ($this->client instanceof Guzzle) {
            $this->client->set_auth($username, $password);
        }
    }
    public function am_on_url(string $url): void
    {
        $host = Uri::retrieve_host($url);
        $config = $this->config;
        $config['url'] = $host;
        $this->_reconfigure($config);
        $page = substr($url, strlen($host));
        if ($page === '') {
            $page = '/';
        }
        $this->debug_section('Host', $host);
        $this->am_on_page($page);
    }
    public function am_on_subdomain(string $subdomain): void
    {
        $url = $this->config['url'];
        $url = preg_replace('#(https?://)(.*\.)(.*\.)#', '$1$3', (string) $url);
        // removing current subdomain
        $url = preg_replace('#(https?://)(.*)#', sprintf('$1%s.$2', $subdomain), $url);
        // inserting new
        $config = $this->config;
        $config['url'] = $url;
        $this->_reconfigure($config);
    }
    protected function on_reconfigure()
    {
        $this->_prepare_session();
    }
    /**
     * Low-level API method.
     * If Codeception commands are not enough, use [Guzzle HTTP Client](https://guzzlephp.org/) methods directly
     *
     * Example:
     *
     * ``` php
     * <?php
     * $I->executeInGuzzle(function (\GuzzleHttp\Client $client) {
     *      $client->get('/get', ['query' => ['foo' => 'bar']]);
     * });
     * ```
     *
     * It is not recommended to use this command on a regular basis.
     * If Codeception lacks important Guzzle Client methods, implement them and submit patches.
     */
    public function execute_in_guzzle(Closure $function): mixed
    {
        return $function($this->guzzle);
    }
    public function _get_response_code(): int|string
    {
        return $this->get_response_status_code();
    }
    public function _initialize_session(): void
    {
        // independent sessions need independent cookies
        $this->client = new Guzzle();
        $this->_prepare_session();
    }
    public function _prepare_session(): void
    {
        $defaults = array_intersect_key($this->config, array_flip($this->guzzle_config_fields));
        $curl_options = [];
        foreach ($this->config['curl'] as $key => $val) {
            if (defined($key)) {
                $curl_options[constant($key)] = $val;
            }
        }
        $this->headers = $this->config['headers'];
        $this->set_cookies_from_options();
        $defaults['base_uri'] = $this->config['url'];
        $defaults['curl'] = $curl_options;
        $handler_stack = Guzzle::create_handler($this->config['handler']);
        if (is_array($this->config['middleware'])) {
            foreach ($this->config['middleware'] as $middleware) {
                $handler_stack->push($middleware);
            }
        }
        $defaults['handler'] = $handler_stack;
        $this->guzzle = new Guzzle_Client($defaults);
        $this->client->set_refresh_max_interval($this->config['refresh_max_interval']);
        $this->client->set_client($this->guzzle);
    }
    /**
     * @return array<string, mixed>
     */
    public function _backup_session()
    {
        return ['client' => $this->client, 'guzzle' => $this->guzzle, 'crawler' => $this->crawler, 'headers' => $this->headers];
    }
    /**
     * @param array<string, mixed> $session
     */
    public function _load_session($session): void
    {
        foreach ($session as $key => $val) {
            $this->{$key} = $val;
        }
    }
    /**
     * @param ?array<string, mixed> $session
     */
    public function _close_session($session = null): void
    {
        unset($session);
    }
}