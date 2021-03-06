<?php
/**
 * This file is a part of the Phystrix library
 *
 * Copyright 2013-2014 oDesk Corporation. All Rights Reserved.
 *
 * This file is licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Tests\Odesk\Phystrix;

use Odesk\Phystrix\AbstractCommand;
use Odesk\Phystrix\Exception\RuntimeException;
use Odesk\Phystrix\RequestCache;
use Odesk\Phystrix\RequestLog;

class CommandTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $circuitBreakerFactory;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $circuitBreaker;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $commandMetricsFactory;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $commandMetrics;

    /**
     * @var CommandMock
     */
    protected $command;

    /**
     * @var RequestLog
     */
    protected $requestLog;

    protected function setUp()
    {
        $this->circuitBreakerFactory
            = $this->getMock('Odesk\Phystrix\CircuitBreakerFactory', array(), array(), '', false);
        $this->circuitBreaker
            = $this->getMock('Odesk\Phystrix\CircuitBreakerInterface', array(), array(), '', false);
        $this->commandMetricsFactory
            = $this->getMock('Odesk\Phystrix\CommandMetricsFactory', array(), array(), '', false);
        $this->commandMetrics = $this->getMock('Odesk\Phystrix\CommandMetrics', array(), array(), '', false);

        $this->command = new CommandMock();
        $this->command->setCommandMetricsFactory($this->commandMetricsFactory);
        $this->command->setRequestCache(new RequestCache());
        $this->requestLog = new RequestLog();
        $this->command->setRequestLog($this->requestLog);
        $this->command->setCircuitBreakerFactory($this->circuitBreakerFactory);
        $this->command->setConfig(new \Zend\Config\Config(array(
            'fallback' => array(
                'enabled' => true,
            ),
            'requestCache' => array(
                'enabled' => true,
            ),
            'requestLog' => array(
                'enabled' => true,
            ),
        ), true));
    }

    /**
     * Something many tests need
     *
     * @param bool $allowRequest (Optional) Whether CB should allow the request
     */
    protected function setUpCommonExpectations($allowRequest = true)
    {
        $this->circuitBreakerFactory->expects($this->once())
            ->method('get')
            ->with('Tests\Odesk\Phystrix\CommandMock')
            ->will($this->returnValue($this->circuitBreaker));
        $this->circuitBreaker->expects($this->once())
            ->method('allowRequest')
            ->will($this->returnValue($allowRequest));
        $this->commandMetricsFactory->expects($this->atLeastOnce())
            ->method('get')
            ->with('Tests\Odesk\Phystrix\CommandMock')
            ->will($this->returnValue($this->commandMetrics));
    }

    /**
     * Makes the command run for 555 milliseconds
     */
    protected function setUpExecutionDelayExpectations()
    {
        // testing execution time was recorded correctly
        global $globalUnitTestPhystrixMicroTime;
        $globalUnitTestPhystrixMicroTime = 1369861562.1266;
        $this->command->simulateDelay = true;
    }

    public function testSetTestMergesConfig()
    {
        $command = new CommandMock();
        $command->setConfig(new \Zend\Config\Config(array('a' => 1), true));
        $command->setConfig(new \Zend\Config\Config(array('b' => 2), true));
        $this->assertAttributeEquals(new \Zend\Config\Config(array('a' => 1, 'b' => 2), true), 'config', $command);
        $command->setConfig(new \Zend\Config\Config(array('c' => 3), true), false); // false to skip merge
        $this->assertAttributeEquals(new \Zend\Config\Config(array('c' => 3), true), 'config', $command);
    }

    public function testExecuteDefaultCommandKey()
    {
        // default command key is the class name
        $this->setUpCommonExpectations();
        $this->command->execute();
    }

    public function testExecutePresetCommandKey()
    {
        $this->commandMetricsFactory->expects($this->atLeastOnce())
            ->method('get')
            ->with('PresetTestCommandKey')
            ->will($this->returnValue($this->commandMetrics));
        $reflection = new \ReflectionClass('Tests\Odesk\Phystrix\CommandMock');
        $property = $reflection->getProperty('commandKey');
        $property->setAccessible(true);
        $property->setValue($this->command, 'PresetTestCommandKey');
        $this->commandMetricsFactory->expects($this->atLeastOnce())
            ->method('get')
            ->with('PresetTestCommandKey')
            ->will($this->returnValue($this->commandMetrics));

        $this->circuitBreakerFactory->expects($this->once())
            ->method('get')
            ->with('PresetTestCommandKey')
            ->will($this->returnValue($this->circuitBreaker));
        $this->circuitBreaker->expects($this->once())
            ->method('allowRequest')
            ->will($this->returnValue(true));

        $this->command->execute();
    }

    public function testExecute()
    {
        $this->setUpCommonExpectations();
        $this->setUpExecutionDelayExpectations();
        $this->circuitBreaker->expects($this->once())
            ->method('markSuccess');
        $this->commandMetrics->expects($this->once())
            ->method('markSuccess');
        $this->assertEmpty($this->requestLog->getExecutedCommands());
        $this->assertEquals(null, $this->command->getExecutionTimeInMilliseconds());
        $this->assertEquals('run result', $this->command->execute());
        $this->assertEquals(array(AbstractCommand::EVENT_SUCCESS), $this->command->getExecutionEvents());
        $this->assertEquals(array($this->command), $this->requestLog->getExecutedCommands());
        $this->assertEquals(555, $this->command->getExecutionTimeInMilliseconds());
    }

    public function testRequestLogOff()
    {
        $this->setUpCommonExpectations();
        $this->command->setConfig(new \Zend\Config\Config(array(
            'requestLog' => array(
                'enabled' => false,
            ),
        )));
        $this->assertEmpty($this->requestLog->getExecutedCommands());
        $this->assertEquals('run result', $this->command->execute());
        $this->assertEmpty($this->requestLog->getExecutedCommands());
    }

    public function testExecuteRequestNotAllowed()
    {
        $this->setUpCommonExpectations(false);

        $this->commandMetrics->expects($this->once())
            ->method('markShortCircuited');
        $this->circuitBreaker->expects($this->never())
            ->method('markSuccess');
        $this->commandMetrics->expects($this->never())
            ->method('markSuccess');
        $this->commandMetrics->expects($this->never())
            ->method('markFailure');

        $this->assertEquals('fallback result', $this->command->execute());
        $this->assertEquals(
            array(AbstractCommand::EVENT_SHORT_CIRCUITED, AbstractCommand::EVENT_FALLBACK_SUCCESS),
            $this->command->getExecutionEvents()
        );
        // execution time is not recorded
        $this->assertEquals(null, $this->command->getExecutionTimeInMilliseconds());
    }

    public function testExecuteRunException()
    {
        $this->setUpCommonExpectations();
        $this->setUpExecutionDelayExpectations();
        $this->commandMetrics->expects($this->never()) // because fallback is enabled and working
            ->method('markExceptionThrown');
        $this->commandMetrics->expects($this->once())
            ->method('markFallbackSuccess');
        $this->commandMetrics->expects($this->once())
            ->method('markFailure');
        $this->command->throwException = true;
        $this->assertEquals('fallback result', $this->command->execute());
        $this->assertEquals(
            array(AbstractCommand::EVENT_FAILURE, AbstractCommand::EVENT_FALLBACK_SUCCESS),
            $this->command->getExecutionEvents()
        );
        $this->assertEquals(555, $this->command->getExecutionTimeInMilliseconds());
        $this->assertEquals(new \DomainException('could not run'), $this->command->getExecutionException());
    }
    
    public function testExecuteFallbackFailed()
    {
        $this->setUpCommonExpectations();
        $this->commandMetrics->expects($this->never())
            ->method('markFallbackSuccess');
        $this->commandMetrics->expects($this->once())
            ->method('markFallbackFailure');
        $this->commandMetrics->expects($this->once())
            ->method('markExceptionThrown');
        $this->commandMetrics->expects($this->once())
            ->method('markFailure');
        $this->command->throwException = true;
        $this->command->throwExceptionInFallback = true;
        try {
            $this->command->execute();
            $this->fail('Exception expected');
        } catch (\Exception $exception) {}
        $this->assertInstanceof('Odesk\Phystrix\Exception\RuntimeException', $exception);
        $this->assertEquals('could not run and failed retrieving fallback', $exception->getMessage());
        $this->assertEquals(
            array(
                AbstractCommand::EVENT_FAILURE,
                AbstractCommand::EVENT_FALLBACK_FAILURE,
                AbstractCommand::EVENT_EXCEPTION_THROWN
            ),
            $this->command->getExecutionEvents()
        );
        $this->assertEquals($exception->getPrevious(), $this->command->getExecutionException());
    }

    public function testRuntimeExceptionPopulated()
    {
        $this->setUpCommonExpectations();
        $this->command->throwException = true;
        $this->command->throwExceptionInFallback = true;
        try {
            $this->command->execute();
            $this->fail('Odesk\Phystrix\Exception\RuntimeException was expected');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf('Odesk\Phystrix\Exception\RuntimeException', $exception);
            $this->assertInstanceOf('DomainException', $exception->getPrevious());
            $this->assertInstanceOf('DomainException', $exception->getFallbackException());
            $this->assertEquals('Tests\Odesk\Phystrix\CommandMock', $exception->getCommandClass());
            $this->assertEquals('could not run and failed retrieving fallback', $exception->getMessage());
            $this->assertEquals('error falling back', $exception->getFallbackException()->getMessage());
        }
    }

    public function testBadRequestExceptionTracksNoMetrics()
    {
        $this->setUpCommonExpectations();
        $this->commandMetrics->expects($this->never())
            ->method('markFallbackSuccess');
        $this->commandMetrics->expects($this->never())
            ->method('markFallbackFailure');
        $this->commandMetrics->expects($this->never())
            ->method('markExceptionThrown');
        $this->commandMetrics->expects($this->never())
            ->method('markFailure');
        $this->command->throwBadRequestException = true;
        $this->setExpectedException('Odesk\Phystrix\Exception\BadRequestException', 'special treatment');
        $this->command->execute();
        // no events logged in this case
        $this->assertEquals(array(), $this->command->getExecutionEvents());
        // execution time is not recorded
        $this->assertEquals(null, $this->command->getExecutionTimeInMilliseconds());
    }

    public function testShortCircuitedExceptionMessage()
    {
        $this->setUpCommonExpectations(false);
        $this->command->throwException = true;
        $this->command->throwExceptionInFallback = true;
        try {
            $this->command->execute();
            $this->fail('Odesk\Phystrix\Exception\RuntimeException was expected');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf('Odesk\Phystrix\Exception\RuntimeException', $exception);
            $this->assertEquals('Short-circuited and failed retrieving fallback', $exception->getMessage());
        }
    }

    public function testExecuteFallbackDisabled()
    {
        $this->setUpCommonExpectations();
        $this->command->setConfig(new \Zend\Config\Config(array('fallback' => array('enabled' => false))));
        $this->commandMetrics->expects($this->never()) // because fallback is disabled
            ->method('markFallbackSuccess');
        $this->commandMetrics->expects($this->never()) // because fallback is disabled
            ->method('markFallbackFailure');
        $this->commandMetrics->expects($this->once())
            ->method('markExceptionThrown');
        $this->commandMetrics->expects($this->once())
            ->method('markFailure');
        $this->command->throwException = true;
        try {
            $this->command->execute();
            $this->fail('Exception expected');
        } catch (\Exception $exception) {}
        $this->assertInstanceof('Odesk\Phystrix\Exception\RuntimeException', $exception);
        $this->assertEquals('could not run and fallback disabled', $exception->getMessage());
        $this->assertEquals(
            array(
                AbstractCommand::EVENT_FAILURE,
                AbstractCommand::EVENT_EXCEPTION_THROWN
            ),
            $this->command->getExecutionEvents()
        );
    }

    public function testResponseFromCache()
    {
        $this->commandMetricsFactory->expects($this->atLeastOnce())
            ->method('get')
            ->with('Tests\Odesk\Phystrix\CommandMock')
            ->will($this->returnValue($this->commandMetrics));
        $requestCache = $this->getMock('Odesk\Phystrix\RequestCache');
        $requestCache->expects($this->once())
            ->method('get')
            ->with('Tests\Odesk\Phystrix\CommandMock', 'test-cache-key')
            ->will($this->returnValue('result from cache'));
        $this->command->cacheKey = 'test-cache-key';
        $this->command->setRequestCache($requestCache);
        $this->circuitBreakerFactory->expects($this->never())->method('get');
        $this->commandMetrics->expects($this->once())->method('markResponseFromCache');
        $this->assertEquals('result from cache', $this->command->execute());
        $this->assertEquals(array(AbstractCommand::EVENT_RESPONSE_FROM_CACHE), $this->command->getExecutionEvents());
    }

    public function testSavesResultToCache()
    {
        $this->setUpCommonExpectations();
        $requestCache = $this->getMock('Odesk\Phystrix\RequestCache');
        $requestCache->expects($this->once())
            ->method('put')
            ->with('Tests\Odesk\Phystrix\CommandMock', 'test-cache-key', 'run result');
        $this->command->cacheKey = 'test-cache-key';
        $this->command->setRequestCache($requestCache);
        $this->assertEquals('run result', $this->command->execute());
    }

    public function testRequestCacheDisabled()
    {
        $this->setUpCommonExpectations();
        $this->command->setConfig(new \Zend\Config\Config(array('requestCache' => array('enabled' => false))));
        $requestCache = $this->getMock('Odesk\Phystrix\RequestCache');
        $requestCache->expects($this->never())
            ->method('get');
        $requestCache->expects($this->never())
            ->method('put');
        $this->command->cacheKey = 'test-cache-key';
        $this->command->setRequestCache($requestCache);
        $this->assertEquals('run result', $this->command->execute());
    }


    public function testRequestCacheGetCacheKeyNotImplemented()
    {
        $this->setUpCommonExpectations();
        $requestCache = $this->getMock('Odesk\Phystrix\RequestCache');
        $requestCache->expects($this->never())
            ->method('get');
        $requestCache->expects($this->never())
            ->method('put');
        $this->command->setRequestCache($requestCache);
        $this->assertEquals('run result', $this->command->execute());
    }
}
