<?php

/*
 * This file is part of the SymfonyCasts ResetPasswordBundle package.
 * Copyright (c) SymfonyCasts <https://symfonycasts.com/>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SymfonyCasts\Bundle\ResetPassword\Tests\UnitTests;

use PHPUnit\Framework\TestCase;
use SymfonyCasts\Bundle\ResetPassword\Exception\ExpiredResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\Exception\InvalidResetPasswordTokenException;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\Generator\ResetPasswordTokenGenerator;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper;
use SymfonyCasts\Bundle\ResetPassword\Tests\Fixtures\ResetPasswordRequestTestFixture;
use SymfonyCasts\Bundle\ResetPassword\Util\ResetPasswordCleaner;

/**
 * @author  Jesse Rushlow <jr@rushlow.dev>
 * @authot  Ryan Weaver   <ryan@symfonycasts.com>
 */
class ResetPasswordHelperTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|ResetPasswordRequestRepositoryInterface
     */
    private $mockRepo;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|ResetPasswordTokenGenerator
     */
    private $mockTokenGenerator;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|ResetPasswordRequestInterface
     */
    private $mockResetRequest;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|ResetPasswordCleaner
     */
    private $mockCleaner;

    /**
     * @var string
     */
    private $randomToken;

    /**
     * @var object
     */
    private $mockUser;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->mockRepo = $this->createMock(ResetPasswordRequestRepositoryInterface::class);
        $this->mockTokenGenerator = $this->createMock(ResetPasswordTokenGenerator::class);
        $this->mockCleaner = $this->createMock(ResetPasswordCleaner::class);
        $this->mockResetRequest = $this->createMock(ResetPasswordRequestInterface::class);
        $this->randomToken = \bin2hex(\random_bytes(20));
        $this->mockUser = new class {};
    }

    private function getPasswordResetHelper(): ResetPasswordHelper
    {
        return new ResetPasswordHelper(
            $this->mockTokenGenerator,
            $this->mockCleaner,
            $this->mockRepo,
            99999999,
            99999999
        );
    }

    /**
     * @covers \SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper::hasUserHisThrottling
     */
    public function testHasUserThrottlingReturnsFalseWithNoLastRequestDate(): void
    {
        $this->mockRepo
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn('1234')
        ;

        $this->mockRepo
            ->expects($this->once())
            ->method('getMostRecentNonExpiredRequestDate')
            ->willReturn(null)
        ;

        $this->mockRepo
            ->method('createResetPasswordRequest')
            ->willReturn(new ResetPasswordRequestTestFixture())
        ;

        $helper = $this->getPasswordResetHelper();
        $helper->generateResetToken($this->mockUser);
    }

    /**
     * @covers \SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelper::hasUserHisThrottling
     */
    public function testHasUserThrottlingReturnsFalseIfNotBeforeThrottleTime(): void
    {
        $this->mockRepo
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn('1234')
        ;

        $mockLastRequestTime = $this->createMock(\DateTimeImmutable::class);
        $mockLastRequestTime
            ->expects($this->once())
            ->method('getTimestamp')
            ->willReturn(1234)
        ;

        $this->mockRepo
            ->expects($this->once())
            ->method('getMostRecentNonExpiredRequestDate')
            ->willReturn($mockLastRequestTime)
        ;

        $this->mockRepo
            ->method('createResetPasswordRequest')
            ->willReturn(new ResetPasswordRequestTestFixture())
        ;

        $helper = $this->getPasswordResetHelper();
        $helper->generateResetToken($this->mockUser);
    }

    public function testExceptionThrownIfRequestBeforeThrottleLimit(): void
    {
        $mockLastRequestTime = $this->createMock(\DateTimeImmutable::class);
        $mockLastRequestTime
            ->expects($this->once())
            ->method('getTimestamp')
            ->willReturn(9999999999)
        ;

        $this->mockRepo
            ->expects($this->once())
            ->method('getMostRecentNonExpiredRequestDate')
            ->willReturn($mockLastRequestTime)
        ;

        $this->expectException(TooManyPasswordRequestsException::class);

        $helper = $this->getPasswordResetHelper();
        $helper->generateResetToken($this->mockUser);
    }

    public function testRemoveResetRequestThrowsExceptionWithEmptyToken(): void
    {
        $this->expectException(InvalidResetPasswordTokenException::class);

        $helper = $this->getPasswordResetHelper();
        $helper->removeResetRequest('');
    }

    public function testRemoveResetRequestRetrievesTokenFromRepository(): void
    {
        $this->mockRepo
            ->expects($this->once())
            ->method('findResetPasswordRequest')
            ->with(\substr($this->randomToken, 0, 20))
            ->willReturn($this->mockResetRequest)
        ;

        $helper = $this->getPasswordResetHelper();
        $helper->removeResetRequest($this->randomToken);
    }

    public function testRemoveResetRequestCallsRepositoryToRemoveResetRequestObject(): void
    {
        $this->mockRepo
            ->method('findResetPasswordRequest')
            ->willReturn($this->mockResetRequest)
        ;

        $this->mockRepo
            ->expects($this->once())
            ->method('removeResetPasswordRequest')
            ->with($this->mockResetRequest)
        ;

        $helper = $this->getPasswordResetHelper();
        $helper->removeResetRequest('1234');
    }

    public function testExceptionThrownIfTokenLengthIsNotOfCorrectSize(): void
    {
        $this->expectException(InvalidResetPasswordTokenException::class);

        $helper = $this->getPasswordResetHelper();
        $helper->validateTokenAndFetchUser(\substr($this->randomToken, 0, 39));
    }

    public function testExceptionIsThrownIfTokenNotFoundDuringValidation(): void
    {
        $this->mockRepo
            ->expects($this->once())
            ->method('findResetPasswordRequest')
            ->willReturn(null)
        ;

        $this->expectException(InvalidResetPasswordTokenException::class);

        $helper = $this->getPasswordResetHelper();
        $helper->validateTokenAndFetchUser($this->randomToken);
    }

    public function testValidateTokenThrowsExceptionOnExpiredResetRequest(): void
    {
        $this->mockResetRequest
            ->expects($this->once())
            ->method('isExpired')
            ->willReturn(true)
        ;

        $this->mockRepo
            ->expects($this->once())
            ->method('findResetPasswordRequest')
            ->with(\substr($this->randomToken, 0, 20))
            ->willReturn($this->mockResetRequest)
        ;

        $this->expectException(ExpiredResetPasswordTokenException::class);

        $helper = $this->getPasswordResetHelper();
        $helper->validateTokenAndFetchUser($this->randomToken);
    }

    public function testValidateTokenFetchesUserIfTokenNotExpired(): void
    {
        $this->mockResetRequest
            ->expects($this->once())
            ->method('isExpired')
            ->willReturn(false)
        ;

        $this->mockResetRequest
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($this->mockUser)
        ;

        $this->mockResetRequest
            ->expects($this->once())
            ->method('getExpiresAt')
            ->willReturn(new \DateTimeImmutable())
        ;

        $this->mockRepo
            ->expects($this->once())
            ->method('findResetPasswordRequest')
            ->with(\substr($this->randomToken, 0, 20))
            ->willReturn($this->mockResetRequest)
        ;

        $helper = $this->getPasswordResetHelper();
        $helper->validateTokenAndFetchUser($this->randomToken);
    }

    public function testValidateTokenThrowsExceptionIfTokenAndVerifierDoNotMatch(): void
    {
        $this->mockResetRequest
            ->expects($this->once())
            ->method('getExpiresAt')
            ->willReturn(new \DateTimeImmutable())
        ;

        $this->mockResetRequest
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($this->mockUser)
        ;

        $this->mockResetRequest
            ->expects($this->once())
            ->method('getHashedToken')
            ->willReturn('1234')
        ;

        $this->mockRepo
            ->expects($this->once())
            ->method('findResetPasswordRequest')
            ->willReturn($this->mockResetRequest)
        ;

        $this->expectException(InvalidResetPasswordTokenException::class);

        $helper = $this->getPasswordResetHelper();
        $helper->validateTokenAndFetchUser($this->randomToken);
    }

    public function testGenerateResetTokenCallsGarbageCollector(): void
    {
        $this->setMockCleanerExpectations();

        $helper = $this->getPasswordResetHelper();
        $helper->generateResetToken($this->mockUser);
    }

    public function testGarbageCollectorCalledDuringValidation(): void
    {
        $this->setMockCleanerExpectations();
        $this->expectException(InvalidResetPasswordTokenException::class);

        $helper = $this->getPasswordResetHelper();
        $helper->validateTokenAndFetchUser($this->randomToken);
    }

    private function setMockCleanerExpectations(): void
    {
        $this->mockCleaner
            ->expects($this->once())
            ->method('handleGarbageCollection')
        ;
    }
}
