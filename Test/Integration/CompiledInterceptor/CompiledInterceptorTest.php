<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Creatuity\Interception\Test\Integration\CompiledInterceptor;

use Creatuity\Interception\Generator\AreasPluginList;
use Creatuity\Interception\Generator\FileCache;
use Creatuity\Interception\Generator\StaticScope;
use Magento\Framework\App\AreaList;
use Magento\Framework\Code\Generator\Io;
use Creatuity\Interception\Generator\CompiledInterceptor;
use Creatuity\Interception\Generator\CompiledPluginList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Interception\InterceptorInterface;
use Creatuity\Interception\Test\Integration\CompiledInterceptor\Custom\Module\Model\SecondItem;
use Creatuity\Interception\Test\Integration\CompiledInterceptor\Custom\Module\Model\ComplexItem;
use Creatuity\Interception\Test\Integration\CompiledInterceptor\Custom\Module\Model\ComplexItemTyped;
use Creatuity\Interception\Test\Integration\CompiledInterceptor\Custom\Module\Model\Item;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * Class CompiledInterceptorTest
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CompiledInterceptorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Io|MockObject
     */
    private $ioGenerator;

    /**
     * @var AreaList|MockObject
     */
    private $areaList;

    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        $this->ioGenerator = $this->getMockBuilder(Io::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->areaList = $this->getMockBuilder(AreaList::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return array
     */
    public function createScopeReaders()
    {
        $readerMap = include __DIR__ . '/../_files/reader_mock_map.php';
        $readerMock = $this->createMock(\Magento\Framework\ObjectManager\Config\Reader\Dom::class);
        $readerMock->expects($this->any())->method('read')->will($this->returnValueMap($readerMap));

        $omMock = $this->createMock(ObjectManager::class);
        $omMock->method('get')->with(\Psr\Log\LoggerInterface::class)->willReturn(new NullLogger());

        $omConfigMock =  $this->getMockForAbstractClass(
            \Magento\Framework\Interception\ObjectManager\ConfigInterface::class
        );

        $omConfigMock->expects($this->any())->method('getOriginalInstanceType')->will($this->returnArgument(0));
        $ret = [];
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        //clear static cache
        (new FileCache())->clean();
        foreach ($readerMap as $readerLine) {
            $ret[$readerLine[0]] = $objectManagerHelper->getObject(
                CompiledPluginList::class,
                [
                    'objectManager' => $omMock,
                    'scope' => new StaticScope($readerLine[0]),
                    'reader' => $readerMock,
                    'omConfig' => $omConfigMock
                ]
            );
        }
        return $ret;
    }

    /**
     * Checks a test case when interceptor generates code for the specified class.
     *
     * @param string $className
     * @param string $resultClassName
     * @param string $fileName
     * @dataProvider interceptorDataProvider
     */
    public function testGenerate($className, $resultClassName, $fileName)
    {
        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        /** @var AreasPluginList $areaPlugins */
        $areaPlugins = $objectManagerHelper->getObject(
            AreasPluginList::class,
            [
                'areaList' => $this->areaList,
                'plugins' => $this->createScopeReaders()
            ]
        );

        /** @var CompiledInterceptor|MockObject $interceptor */
        $interceptor = $this->getMockBuilder(CompiledInterceptor::class)
            ->setMethods(['_validateData'])
            ->setConstructorArgs(
                [
                    $areaPlugins,
                    $className,
                    $resultClassName,
                    $this->ioGenerator,
                    null,
                    null
                ]
            )
            ->getMock();

        $this->ioGenerator->method('generateResultFileName')->with('\\' . $resultClassName)
            ->willReturn($fileName . '.php');

        $code = file_get_contents(__DIR__ . '/_out_interceptors/' . $fileName . '.txt');

        $this->ioGenerator->method('writeResultFile')->with($fileName . '.php', $code);
        $interceptor->method('_validateData')->willReturn(true);

        $generated = $interceptor->generate();
        $this->assertEquals($fileName . '.php', $generated, 'Generated interceptor is invalid.');

        /*
        eval( $code );
        $className  = "\\$resultClassName";
        $interceptor = new $className(
            ??,
            new StaticScope('frontend')
        );
        $interceptor->getName();
        */
    }

    /**
     * Checks that generated interceptors implement InterceptorInterface like Magento interceptors do.
     *
     * @param string $className
     * @param string $resultClassName
     * @dataProvider interceptorDataProvider
     */
    public function testGeneratedInterceptorImplementsInterceptorInterface($className, $resultClassName)
    {
        $interceptor = $this->createGeneratedInterceptor($className, $resultClassName);

        $this->assertInstanceOf(InterceptorInterface::class, $interceptor);
        $this->assertInstanceOf($className, $interceptor);
        $this->assertTrue((new \ReflectionClass($interceptor))->implementsInterface(InterceptorInterface::class));
    }

    /**
     * Checks that ___callParent() calls the original method without running any plugins.
     */
    public function testCallParentBypassesPlugins()
    {
        /** @var ComplexItem|InterceptorInterface $interceptor */
        $interceptor = $this->createGeneratedInterceptor(ComplexItem::class, ComplexItem::class . '\Interceptor');

        $this->assertNull($interceptor->___callParent('setValue', ['value' => 'original']));
        $this->assertEquals('original', $interceptor->___callParent('getName', []));

        $interceptor->___callParent('secondVariadicParameter', ['first', 'second', 'third']);
        $this->assertEquals('first', $interceptor->___callParent('getName', []));

        $this->assertSame($interceptor, $interceptor->___callParent('returnsSelf', []));

        $this->expectException(\Error::class);
        $interceptor->getName();
    }

    /**
     * Generates, loads and instantiates an interceptor whose plugins cannot be resolved, so any plugin call fails.
     *
     * @param string $className
     * @param string $resultClassName
     * @return object
     */
    private function createGeneratedInterceptor($className, $resultClassName)
    {
        if (!class_exists($resultClassName, false)) {
            $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
            /** @var AreasPluginList $areaPlugins */
            $areaPlugins = $objectManagerHelper->getObject(
                AreasPluginList::class,
                [
                    'areaList' => $this->areaList,
                    'plugins' => $this->createScopeReaders()
                ]
            );

            /** @var CompiledInterceptor|MockObject $interceptor */
            $interceptor = $this->getMockBuilder(CompiledInterceptor::class)
                ->setMethods(['_validateData'])
                ->setConstructorArgs([$areaPlugins, $className, $resultClassName, $this->ioGenerator, null, null])
                ->getMock();
            $interceptor->method('_validateData')->willReturn(true);

            $code = null;
            $this->ioGenerator->method('generateResultFileName')->willReturn('Interceptor.php');
            $this->ioGenerator->method('writeResultFile')->willReturnCallback(
                function ($fileName, $content) use (&$code) {
                    $code = $content;
                }
            );

            $this->assertEquals('Interceptor.php', $interceptor->generate(), implode(', ', $interceptor->getErrors()));
            eval($code);
        }

        return new $resultClassName(
            $this->createMock(\Magento\Framework\ObjectManagerInterface::class),
            new StaticScope('global')
        );
    }

    /**
     * Gets list of interceptor samples.
     *
     * @return array
     */
    public function interceptorDataProvider()
    {
        return [
            [
                Item::class,
                Item::class . '\Interceptor',
                'Item'
            ],
            [
                ComplexItem::class,
                ComplexItem::class . '\Interceptor',
                'ComplexItem'
            ],
            [
                ComplexItemTyped::class,
                ComplexItemTyped::class . '\Interceptor',
                'ComplexItemTyped'
            ],
            [
                SecondItem::class,
                SecondItem::class . '\Interceptor',
                'SecondItem'
            ],
        ];
    }
}
