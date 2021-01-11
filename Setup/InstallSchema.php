<?php
/**
 * Copyright 2015 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Signifyd\Connect\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

/**
 * @codeCoverageIgnore
 */
class InstallSchema implements InstallSchemaInterface
{
    protected $logger;

    public function __construct(
        \Signifyd\Connect\Logger\Install $logger
    ) {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        try {
            $setup->startSetup();

            if (!$setup->tableExists('signifyd_connect_case')) {
                $table = $setup->getConnection()->newTable($setup->getTable('signifyd_connect_case'));
                $table->addColumn(
                    'order_increment',
                    Table::TYPE_TEXT,
                    255,
                    [
                        'nullable' => false
                    ],
                    'Order Increment ID'
                )
                    ->addColumn(
                        'signifyd_status',
                        Table::TYPE_TEXT,
                        255,
                        [
                            'nullable' => false,
                            'default' => 'PENDING'
                        ],
                        'Signifyd Status'
                    )
                    ->addColumn(
                        'code',
                        Table::TYPE_TEXT,
                        255,
                        [
                            'nullable' => false,
                            'primary' => true
                        ],
                        'Code'
                    )
                    ->addColumn(
                        'score',
                        Table::TYPE_FLOAT,
                        null,
                        [],
                        'Score'
                    )
                    ->addColumn(
                        'guarantee',
                        Table::TYPE_TEXT,
                        64,
                        [
                            'nullable' => false,
                            'default' => 'N/A'
                        ],
                        'Guarantee Status'
                    )
                    ->addColumn(
                        'entries_text',
                        Table::TYPE_TEXT,
                        null,
                        ['nullable' => false],
                        'Entries'
                    )
                    ->addColumn(
                        'created',
                        Table::TYPE_TIMESTAMP,
                        null,
                        [],
                        'Creation Time'
                    )
                    ->addColumn(
                        'updated',
                        Table::TYPE_TIMESTAMP,
                        null,
                        [],
                        'Update Time'
                    )
                    ->addColumn(
                        'magento_status',
                        Table::TYPE_TEXT,
                        255,
                        [
                            'nullable' => false,
                            'default' => 'waiting_submission'
                        ],
                        'Magento Status'
                    )
                    ->addColumn(
                        'order_id',
                        Table::TYPE_INTEGER,
                        10,
                        [
                            'nullable' => false,
                            'unsigned' => true
                        ],
                        'Order Id'
                    )
                    ->addForeignKey(
                        $setup->getFkName('signifyd_connect_case', 'order_id', 'sales_order', 'entity_id'),
                        'order_id',
                        $setup->getTable('sales_order'),
                        'entity_id',
                        \Magento\Framework\DB\Ddl\Table::ACTION_NO_ACTION
                    )
                    ->addIndex('index_magento_status', 'magento_status')
                    ->setComment('Signifyd Cases');
                $setup->getConnection()->createTable($table);
            }

            // The plan here is to add the signifyd case data directly to the order tables
            $tableName = $setup->getTable('sales_order');
            $gridTableName = $setup->getTable('sales_order_grid');

            if ($setup->getConnection()->isTableExists($tableName)) {
                $columns = [
                    'signifyd_score' => [
                        'type' => Table::TYPE_FLOAT,
                        'default' => null,
                        'comment' => 'Score',
                    ],
                    'signifyd_guarantee' => [
                        'type' => Table::TYPE_TEXT,
                        'LENGTH' => 64,
                        'default' => 'N/A',
                        'nullable' => false,
                        'comment' => 'Guarantee Status',
                    ],
                    'signifyd_code' => [
                        'type' => Table::TYPE_TEXT,
                        'LENGTH' => 255,
                        'default' => '',
                        'nullable' => false,
                        'comment' => 'Code',
                    ],
                ];

                try {
                    /** @var \Magento\Framework\DB\Adapter\Pdo\Mysql $connection */
                    $connection = $setup->getConnection();

                    foreach ($columns as $name => $definition) {
                        $connection->addColumn($tableName, $name, $definition);
                        $connection->addColumn($gridTableName, $name, $definition);
                    }
                } catch (\Exception $e) {
                    throw new \Zend_Db_Exception('Error modifying sales_order table: ' . $e->getMessage());
                }
            }

            $this->logger->debug('Installation completed successfully');
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
