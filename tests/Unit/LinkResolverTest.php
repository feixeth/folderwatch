<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ZEDD
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FolderWatch\Tests\Unit;

use OCA\FolderWatch\Service\LinkResolver;
use OCA\FolderWatch\Service\RequestContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LinkResolverTest extends TestCase {
	private IURLGenerator&MockObject $urls;
	private LoggerInterface&MockObject $logger;
	private FakeAppConfig $appConfig;
	private int $now = 1_790_000_000;

	protected function setUp(): void {
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $params): string => 'https://request.example/index.php/f/' . $params['fileid']);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig = FakeAppConfig::create($this);
	}

	/** @param list<string>|null $trusted */
	private function resolver(bool $web, ?string $cliUrl, ?array $trusted = null): LinkResolver {
		$context = $this->createMock(RequestContext::class);
		$context->method('isWebRequest')->willReturn($web);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		return new LinkResolver($context, $this->urls, FakeSystemConfig::create($this, array_filter(['overwrite.cli.url' => $cliUrl, 'trusted_domains' => $trusted], static fn (mixed $value): bool => $value !== null)), $this->appConfig->config, $time, $this->logger);
	}

	public function testInAWebRequestTheRequestAddressIsKeptWhenItIsTheConfiguredOne(): void {
		$this->logger->expects($this->never())->method('warning');
		foreach (['https://request.example', 'https://request.example/', 'https://REQUEST.example'] as $cliUrl) {
			$this->assertSame('https://request.example/index.php/f/42', $this->resolver(true, $cliUrl)->fileUrl(42), $cliUrl);
		}
		// whatever overwrite.cli.url says about trusted_domains
		$this->assertSame('https://request.example/index.php/f/42', $this->resolver(true, 'https://request.example', ['other.example'])->fileUrl(42));
	}

	public function testARequestThatCameInByAnotherNameGetsItsLinkRebuiltOnTheConfiguredAddress(): void {
		$this->logger->expects($this->never())->method('warning');
		$this->assertSame('https://cloud.example.org/index.php/f/42', $this->resolver(true, 'https://cloud.example.org')->fileUrl(42));
		$this->assertSame('https://example.org/nextcloud/index.php/f/42', $this->resolver(true, 'https://example.org/nextcloud/')->fileUrl(42), 'sub-directory install');
		$this->assertSame('https://cloud.example.org/index.php/f/42', $this->resolver(true, 'https://cloud.example.org', ['request.example'])->fileUrl(42), 'trusted_domains does not matter when overwrite.cli.url is usable');
	}

	public function testARequestOnAnotherSchemeOrPortIsRebuiltToo(): void {
		$this->assertSame('http://request.example/index.php/f/42', $this->resolver(true, 'http://request.example')->fileUrl(42), 'behind a proxy that hides https');
		$this->assertSame('https://request.example:8443/index.php/f/42', $this->resolver(true, 'https://request.example:8443')->fileUrl(42));
	}

	public function testWithoutAUsableConfiguredAddressTheFirstTrustedDomainDecides(): void {
		$this->logger->expects($this->never())->method('warning');
		foreach ([null, '', 'http://localhost', 'http://127.0.0.1:8080'] as $cliUrl) {
			$label = (string)$cliUrl;
			$this->assertSame('https://request.example/index.php/f/42', $this->resolver(true, $cliUrl, ['request.example', 'second.example'])->fileUrl(42), $label);
			$this->assertSame('https://request.example/index.php/f/42', $this->resolver(true, $cliUrl, ['Request.example'])->fileUrl(42), $label . ' (capitals)');
			$this->assertSame('https://request.example/index.php/f/42', $this->resolver(true, $cliUrl, ['request.example:443'])->fileUrl(42), $label . ' (with the port)');
		}
	}

	public function testWithoutAUsableConfiguredAddressARequestFromAnotherNameGetsNoLink(): void {
		foreach ([
			'a name that is not the first trusted domain' => ['second.example', 'request.example'],
			'an internal address' => ['192.168.1.10'],
			'localhost as the first trusted domain' => ['localhost', 'request.example'],
			'loopback as the first trusted domain' => ['127.0.0.1'],
			'no trusted domain' => [],
			'an empty one' => [''],
		] as $label => $trusted) {
			$this->assertNull($this->resolver(true, '', $trusted)->fileUrl(42), $label);
		}
		$this->assertNull($this->resolver(true, null)->fileUrl(42), 'nothing configured at all');
	}

	public function testTheWarningIsAlsoGivenForAWebRequestWithoutAReliableAddress(): void {
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('overwrite.cli.url'));
		$resolver = $this->resolver(true, '', ['second.example']);
		$resolver->fileUrl(1);
		$resolver->fileUrl(2);
	}

	public function testOutsideAWebRequestTheAddressComesFromOverwriteCliUrl(): void {
		$this->assertSame('https://cloud.example.org/nextcloud/index.php/f/42', $this->resolver(false, 'https://cloud.example.org/nextcloud/')->fileUrl(42));
	}

	public function testWithoutAnUsableAddressThereIsNoLink(): void {
		foreach ([null, '', 'http://localhost', 'http://127.0.0.1:8080', 'not a url'] as $cliUrl) {
			$this->assertNull($this->resolver(false, $cliUrl)->fileUrl(42), (string)$cliUrl);
		}
	}

	public function testTheWarningIsLoggedAtMostOncePerDay(): void {
		$this->logger->expects($this->exactly(2))->method('warning')->with($this->stringContains('overwrite.cli.url'));
		$resolver = $this->resolver(false, 'http://localhost');

		$resolver->fileUrl(1);
		$resolver->fileUrl(2);
		$this->now += 3600;
		$resolver->fileUrl(3);
		$this->assertCount(1, $this->appConfig->values);

		$this->now += 86400;
		$this->resolver(false, 'http://localhost')->fileUrl(4);
	}

	public function testNoWarningWhenTheAddressIsFine(): void {
		$this->logger->expects($this->never())->method('warning');
		$this->resolver(false, 'https://cloud.example.org')->fileUrl(1);
	}

	public function testAnswersAreKeptPerFile(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('linkToRouteAbsolute')->willReturn('https://request.example/f/1');
		$context = $this->createMock(RequestContext::class);
		$context->method('isWebRequest')->willReturn(true);
		$resolver = new LinkResolver($context, $urls, $this->createMock(IConfig::class), $this->appConfig->config, $this->createMock(ITimeFactory::class), $this->logger);
		$resolver->fileUrl(1);
		$resolver->fileUrl(1);
	}

	public function testWhenTheFilesRouteIsMissingTheDirectAddressIsUsed(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willThrowException(new \RuntimeException('no route'));
		$urls->method('getAbsoluteURL')->with('/f/9')->willReturn('https://request.example/f/9');
		$context = $this->createMock(RequestContext::class);
		$context->method('isWebRequest')->willReturn(true);
		$resolver = new LinkResolver($context, $urls, FakeSystemConfig::create($this, ['overwrite.cli.url' => 'https://request.example']), $this->appConfig->config, $this->createMock(ITimeFactory::class), $this->logger);
		$this->assertSame('https://request.example/f/9', $resolver->fileUrl(9));
	}
}
