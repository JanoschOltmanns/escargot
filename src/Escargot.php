<?php

declare(strict_types=1);

/*
 * Escargot
 *
 * @copyright  Copyright (c) 2019, terminal42 gmbh
 * @author     terminal42 gmbh <info@terminal42.ch>
 * @license    MIT
 */

namespace Terminal42\Escargot;

use Nyholm\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Terminal42\Escargot\Event\ExcludedByRobotsMetaTagEvent;
use Terminal42\Escargot\Event\ExcludedByUriFilterEvent;
use Terminal42\Escargot\Event\FinishedCrawlingEvent;
use Terminal42\Escargot\Event\RequestExceptionEvent;
use Terminal42\Escargot\Event\SuccessfulResponseEvent;
use Terminal42\Escargot\Event\UnsuccessfulResponseEvent;
use Terminal42\Escargot\Exception\InvalidJobIdException;
use Terminal42\Escargot\Filter\DefaultUriFilter;
use Terminal42\Escargot\Filter\UriFilterInterface;
use Terminal42\Escargot\Queue\QueueInterface;

final class Escargot
{
    private const DEFAULT_USER_AGENT = 'terminal42/escargot';

    /**
     * @var QueueInterface
     */
    private $queue;

    /**
     * @var string
     */
    private $jobId;

    /**
     * @var BaseUriCollection
     */
    private $baseUris;

    /**
     * @var HttpClientInterface|null
     */
    private $client;

    /**
     * @var EventDispatcherInterface|null
     */
    private $eventDispatcher;

    /**
     * @var UriFilterInterface|null
     */
    private $uriFilter;

    /**
     * Whether or not to request
     * the robots.txt and include
     * URIs found in the sitemaps.
     * Enabled by default.
     *
     * @var bool
     */
    private $includeSitemaps = true;

    /**
     * Maximum number of requests
     * Escargot is going to
     * execute.
     * 0 means no limit.
     *
     * @var int
     */
    private $maxRequests = 0;

    /**
     * Maximum depth Escargot
     * is going to crawl.
     * 0 means no limit.
     *
     * @var int
     */
    private $maxDepth = 0;

    /**
     * Request delay in microseconds.
     * 0 means no delay.
     *
     * @var int
     */
    private $requestDelay = 0;

    /**
     * Maximum concurrent requests
     * that are being sent.
     *
     * @var int
     */
    private $concurrency = 10;

    /**
     * @var int
     */
    private $requestsSent = 0;

    /**
     * @var int
     */
    private $runningRequests = 0;

    private function __construct(QueueInterface $queue, string $jobId, BaseUriCollection $baseUris, HttpClientInterface $client = null)
    {
        $this->client = $client;
        $this->queue = $queue;
        $this->jobId = $jobId;
        $this->baseUris = $baseUris;
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        if (null === $this->eventDispatcher) {
            $this->eventDispatcher = new EventDispatcher();
        }

        return $this->eventDispatcher;
    }

    public function getUriFilter(): UriFilterInterface
    {
        if (null === $this->uriFilter) {
            $this->uriFilter = new DefaultUriFilter($this);
        }

        return $this->uriFilter;
    }

    public function setUriFilter(UriFilterInterface $uriFilter): self
    {
        $this->uriFilter = $uriFilter;

        return $this;
    }

    public function includeSitemaps(): bool
    {
        return $this->includeSitemaps;
    }

    public function setIncludeSitemaps(bool $includeSitemaps): self
    {
        $this->includeSitemaps = $includeSitemaps;

        return $this;
    }

    public function setMaxRequests(int $maxRequests): void
    {
        $this->maxRequests = $maxRequests;
    }

    public function setConcurrency(int $concurrency): void
    {
        $this->concurrency = $concurrency;
    }

    public function setMaxDepth(int $maxDepth): self
    {
        $this->maxDepth = $maxDepth;

        return $this;
    }

    public function getRequestDelay(): int
    {
        return $this->requestDelay;
    }

    public function setRequestDelay(int $requestDelay): self
    {
        $this->requestDelay = $requestDelay;

        return $this;
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): self
    {
        $this->getEventDispatcher()->addSubscriber($subscriber);

        return $this;
    }

    public function getClient(): HttpClientInterface
    {
        if (null === $this->client) {
            $this->client = HttpClient::create(['headers' => ['User-Agent' => self::DEFAULT_USER_AGENT]]);
        }

        return $this->client;
    }

    public function getQueue(): QueueInterface
    {
        return $this->queue;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getBaseUris(): BaseUriCollection
    {
        return $this->baseUris;
    }

    public function getMaxRequests(): int
    {
        return $this->maxRequests;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function getConcurrency(): int
    {
        return $this->concurrency;
    }

    public function getRequestsSent(): int
    {
        return $this->requestsSent;
    }

    /**
     * @throws InvalidJobIdException if the provided job ID could not be retrieved by the queue
     */
    public static function createFromJobId(string $jobId, QueueInterface $queue, HttpClientInterface $client = null): self
    {
        if (!$queue->isJobIdValid($jobId)) {
            throw new InvalidJobIdException(sprintf('Job ID "%s" is invalid!', $jobId));
        }

        return new self(
            $queue,
            $jobId,
            $queue->getBaseUris($jobId),
            $client
        );
    }

    public static function create(BaseUriCollection $baseUris, QueueInterface $queue, HttpClientInterface $client = null): self
    {
        if (0 === \count($baseUris)) {
            throw new InvalidJobIdException('Cannot create an Escargot instance with an empty BaseUriCollection!');
        }

        $jobId = $queue->createJobId($baseUris);

        return new self(
            $queue,
            $jobId,
            $baseUris,
            $client
        );
    }

    public function crawl(): void
    {
        // We're finished if we have reached the max requests or the queue is empty
        // and no request is being processed anymore
        if (0 === $this->runningRequests
            && ($this->isMaxRequestsReached() || null === $this->queue->getNext($this->jobId))
        ) {
            $this->getEventDispatcher()->dispatch(new FinishedCrawlingEvent($this));

            return;
        }

        $this->processResponses($this->prepareResponses());
    }

    private function processResponses(array $responses): void
    {
        foreach ($this->getClient()->stream($responses) as $response => $chunk) {
            try {
                if ($chunk->isFirst()) {
                    if (200 !== $response->getStatusCode()) {
                        --$this->runningRequests;
                        $response->cancel();
                        $this->getEventDispatcher()->dispatch(new UnsuccessfulResponseEvent($this, $response));
                        continue;
                    }

                    // We're an HTML crawler, so we reject everything that's not text/html immediately
                    if (!isset($response->getHeaders()['content-type'][0])
                        || false === strpos($response->getHeaders()['content-type'][0], 'text/html')
                    ) {
                        // TODO: another event?
                        --$this->runningRequests;
                        $response->cancel();
                        continue;
                    }
                }

                if ($chunk->isLast()) {
                    --$this->runningRequests;

                    // Trigger event
                    $this->getEventDispatcher()->dispatch(new SuccessfulResponseEvent($this, $response));

                    // Process
                    $this->processResponse($response);
                }
            } catch (TransportExceptionInterface | RedirectionExceptionInterface | ClientExceptionInterface | ServerExceptionInterface $e) {
                --$this->runningRequests;
                $this->getEventDispatcher()->dispatch(new RequestExceptionEvent($this, $e, $response));
            }
        }

        // Continue crawling
        $this->crawl();
    }

    private function prepareResponses(): array
    {
        $responses = [];

        while (!$this->isMaxConcurrencyReached()
            && !$this->isMaxRequestsReached()
            && ($crawlUri = $this->queue->getNext($this->jobId))
        ) {
            // Already processed, ignore
            if ($crawlUri->isProcessed()) {
                continue;
            }

            // Otherwise mark as processed
            $crawlUri->markProcessed();
            $this->queue->add($this->jobId, $crawlUri);

            // Request delay
            if (0 !== $this->requestDelay) {
                usleep($this->requestDelay);
            }

            // If this is a base URI we check the robots.txt for sitemap entries if enabled
            if ($this->includeSitemaps() && 0 === $crawlUri->getLevel()) {
                $this->handleSitemap($crawlUri->getUri());
            }

            try {
                $responses[] = $this->getClient()->request('GET', (string) $crawlUri->getUri(), [
                    'user_data' => $crawlUri,
                ]);
                ++$this->runningRequests;
                ++$this->requestsSent;
            } catch (TransportExceptionInterface $e) {
                --$this->runningRequests;

                $this->getEventDispatcher()->dispatch(new RequestExceptionEvent($this, $e));
            }
        }

        return $responses;
    }

    private function handleSitemap(UriInterface $uri): void
    {
        // Make sure we get the correct URI to the robots.txt
        $uri = $uri->withPath('/robots.txt')->withFragment('')->withQuery('');

        try {
            $response = $this->getClient()->request('GET', (string) $uri);
        } catch (TransportExceptionInterface $e) {
            // TODO: event?
            return;
        }

        if (null === $response || 200 !== $response->getStatusCode()) {
            return;
        }

        $robotsTxtContent = $response->getContent();
        $foundOn = new CrawlUri($uri, 1, true); // Level 1 because 0 is the base URI and 1 is the robots.txt

        // Extract sitemaps
        foreach (explode("\n", $robotsTxtContent) as $line) {
            $line = trim($line);

            if (\strlen($line) < 8 || 0 !== substr_compare($line, 'Sitemap:', 0, 8, true)) {
                continue;
            }

            // Get URI and request
            $sitemapUri = trim(substr($line, 8));

            try {
                $response = $this->getClient()->request('GET', $sitemapUri);
            } catch (TransportExceptionInterface $e) {
                continue;
            }

            if (null === $response || 200 !== $response->getStatusCode()) {
                continue;
            }

            $urls = new \SimpleXMLElement($response->getContent());

            foreach ($urls as $url) {
                // Add it to the queue if not present already
                $this->addUriToQueue(new Uri((string) $url->loc), $foundOn);
            }
        }
    }

    private function isMaxRequestsReached(): bool
    {
        return 0 !== $this->maxRequests && $this->requestsSent >= $this->maxRequests;
    }

    private function isMaxConcurrencyReached(): bool
    {
        return $this->runningRequests >= $this->concurrency;
    }

    private function processResponse(ResponseInterface $response): void
    {
        /** @var CrawlUri $currentCrawlUri */
        $currentCrawlUri = $response->getInfo('user_data');

        // Stop crawling if we have reached max depth
        if (0 !== $this->maxDepth && $this->maxDepth <= $currentCrawlUri->getLevel()) {
            // TODO: another event?
            return;
        }

        // Skip responses that contain an X-Robots-Tag header with nofollow
        if (isset($headers['x-robots-tag'][0]) && false !== strpos($response->getHeaders()['x-robots-tag'][0], 'nofollow')) {
            // TODO: another event?
            return;
        }

        // Skip responses that contain nofollow in the robots meta tag
        $crawler = new Crawler($response->getContent());
        $metaCrawler = $crawler->filter('head meta[name="robots"]');
        $robotsMeta = $metaCrawler->count() ? $metaCrawler->first()->attr('content') : '';

        // We could early return here but for debugging purposes
        // it's better to still crawl all the links and fire events
        // so that one can spot why a certain link was not followed.
        $robotsMetaNofollow = false !== strpos($robotsMeta, 'nofollow');

        // Now crawl for links
        $linkCrawler = $crawler->filter('a');
        foreach ($linkCrawler as $node) {
            $link = new Link($node, (string) $currentCrawlUri->getUri()->withPath('')->withQuery('')->withFragment(''));
            $uri = new Uri($link->getUri());

            // Normalize uri
            $uri = CrawlUri::normalizeUri($uri);

            // Filtered by <meta name="robots" content="nofollow">
            if ($robotsMetaNofollow) {
                // Add it to the queue and mark processed so the event is only dispatched once per URI
                $wasAdded = $this->addUriToQueue($uri, $currentCrawlUri, true);
                if ($wasAdded) {
                    $this->getEventDispatcher()->dispatch(new ExcludedByRobotsMetaTagEvent($this, $uri));
                }
                continue;
            }

            // Ask the URI filter if we should even crawl that URI
            if (!$this->getUriFilter()->shouldCrawl($uri, $node)) {
                // Add it to the queue and mark processed so the event is only dispatched once per URI
                $wasAdded = $this->addUriToQueue($uri, $currentCrawlUri, true);

                // Only dispatch event once per URI
                if ($wasAdded) {
                    $this->getEventDispatcher()->dispatch(new ExcludedByUriFilterEvent($this, $uri, $node));
                }

                continue;
            }

            // Mark URI to process
            $this->addUriToQueue($uri, $currentCrawlUri);
        }
    }

    /**
     * Adds an URI to the queue if not present already.
     *
     * @return bool True if it was added and false if it existed already before
     */
    private function addUriToQueue(UriInterface $uri, CrawlUri $foundOn, bool $processed = false): bool
    {
        $crawlUrl = $this->queue->get($this->jobId, $uri);
        if (null === $crawlUrl) {
            $crawlUrl = new CrawlUri($uri, $foundOn->getLevel() + 1, $processed, $foundOn->getUri());
            $this->queue->add($this->jobId, $crawlUrl);

            return true;
        }

        return false;
    }
}
