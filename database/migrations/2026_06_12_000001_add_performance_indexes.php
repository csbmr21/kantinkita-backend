<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add performance indexes to frequently queried columns.
 * Safe version: checks for column/index existence before adding.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (Schema::hasColumn('orders', 'status') && !$this->indexExists('orders', 'idx_orders_status'))
                    $table->index('status', 'idx_orders_status');
                if (Schema::hasColumn('orders', 'tenant_id') && !$this->indexExists('orders', 'idx_orders_tenant_id'))
                    $table->index('tenant_id', 'idx_orders_tenant_id');
                if (Schema::hasColumn('orders', 'user_id') && !$this->indexExists('orders', 'idx_orders_user_id'))
                    $table->index('user_id', 'idx_orders_user_id');
                if (Schema::hasColumn('orders', 'created_at') && !$this->indexExists('orders', 'idx_orders_created_at'))
                    $table->index('created_at', 'idx_orders_created_at');
                if (Schema::hasColumn('orders', 'tenant_id') && Schema::hasColumn('orders', 'status') && !$this->indexExists('orders', 'idx_orders_tenant_status'))
                    $table->index(['tenant_id', 'status'], 'idx_orders_tenant_status');
                if (Schema::hasColumn('orders', 'user_id') && Schema::hasColumn('orders', 'status') && !$this->indexExists('orders', 'idx_orders_user_status'))
                    $table->index(['user_id', 'status'], 'idx_orders_user_status');
                if (Schema::hasColumn('orders', 'status') && Schema::hasColumn('orders', 'expires_at') && !$this->indexExists('orders', 'idx_orders_status_expires'))
                    $table->index(['status', 'expires_at'], 'idx_orders_status_expires');
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (Schema::hasColumn('order_items', 'order_id') && !$this->indexExists('order_items', 'idx_order_items_order_id'))
                    $table->index('order_id', 'idx_order_items_order_id');
                if (Schema::hasColumn('order_items', 'menu_id') && !$this->indexExists('order_items', 'idx_order_items_menu_id'))
                    $table->index('menu_id', 'idx_order_items_menu_id');
            });
        }

        if (Schema::hasTable('menus')) {
            Schema::table('menus', function (Blueprint $table) {
                if (Schema::hasColumn('menus', 'tenant_id') && !$this->indexExists('menus', 'idx_menus_tenant_id'))
                    $table->index('tenant_id', 'idx_menus_tenant_id');
                if (Schema::hasColumn('menus', 'category_id') && !$this->indexExists('menus', 'idx_menus_category_id'))
                    $table->index('category_id', 'idx_menus_category_id');
                if (Schema::hasColumn('menus', 'is_available') && !$this->indexExists('menus', 'idx_menus_is_available'))
                    $table->index('is_available', 'idx_menus_is_available');
                if (Schema::hasColumn('menus', 'tenant_id') && Schema::hasColumn('menus', 'is_available') && !$this->indexExists('menus', 'idx_menus_tenant_available'))
                    $table->index(['tenant_id', 'is_available'], 'idx_menus_tenant_available');
            });
        }

        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                if (Schema::hasColumn('categories', 'tenant_id') && !$this->indexExists('categories', 'idx_categories_tenant_id'))
                    $table->index('tenant_id', 'idx_categories_tenant_id');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'role_id') && !$this->indexExists('users', 'idx_users_role_id'))
                    $table->index('role_id', 'idx_users_role_id');
                if (Schema::hasColumn('users', 'company_code') && !$this->indexExists('users', 'idx_users_company_code'))
                    $table->index('company_code', 'idx_users_company_code');
                if (Schema::hasColumn('users', 'role') && !$this->indexExists('users', 'idx_users_role'))
                    $table->index('role', 'idx_users_role');
            });
        }

        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                if (Schema::hasColumn('tenants', 'company_code') && !$this->indexExists('tenants', 'idx_tenants_company_code'))
                    $table->index('company_code', 'idx_tenants_company_code');
                if (Schema::hasColumn('tenants', 'status') && Schema::hasColumn('tenants', 'is_deleted') && !$this->indexExists('tenants', 'idx_tenants_status_deleted'))
                    $table->index(['status', 'is_deleted'], 'idx_tenants_status_deleted');
            });
        }

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (Schema::hasColumn('subscriptions', 'tenant_id') && !$this->indexExists('subscriptions', 'idx_subscriptions_tenant_id'))
                    $table->index('tenant_id', 'idx_subscriptions_tenant_id');
                if (Schema::hasColumn('subscriptions', 'status') && !$this->indexExists('subscriptions', 'idx_subscriptions_status'))
                    $table->index('status', 'idx_subscriptions_status');
            });
        }

        if (Schema::hasTable('activity_logs')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                if (Schema::hasColumn('activity_logs', 'user_id') && !$this->indexExists('activity_logs', 'idx_activity_logs_user_id'))
                    $table->index('user_id', 'idx_activity_logs_user_id');
                if (Schema::hasColumn('activity_logs', 'created_at') && !$this->indexExists('activity_logs', 'idx_activity_logs_created_at'))
                    $table->index('created_at', 'idx_activity_logs_created_at');
            });
        }

        if (Schema::hasTable('error_logs')) {
            Schema::table('error_logs', function (Blueprint $table) {
                if (Schema::hasColumn('error_logs', 'resolved_status') && !$this->indexExists('error_logs', 'idx_error_logs_resolved_status'))
                    $table->index('resolved_status', 'idx_error_logs_resolved_status');
                if (Schema::hasColumn('error_logs', 'created_at') && !$this->indexExists('error_logs', 'idx_error_logs_created_at'))
                    $table->index('created_at', 'idx_error_logs_created_at');
            });
        }
    }

    public function down(): void
    {
        $dropIfExists = function (string $table, string $index) {
            if (Schema::hasTable($table) && $this->indexExists($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
            }
        };

        $dropIfExists('orders', 'idx_orders_status');
        $dropIfExists('orders', 'idx_orders_tenant_id');
        $dropIfExists('orders', 'idx_orders_user_id');
        $dropIfExists('orders', 'idx_orders_created_at');
        $dropIfExists('orders', 'idx_orders_tenant_status');
        $dropIfExists('orders', 'idx_orders_user_status');
        $dropIfExists('orders', 'idx_orders_status_expires');
        $dropIfExists('order_items', 'idx_order_items_order_id');
        $dropIfExists('order_items', 'idx_order_items_menu_id');
        $dropIfExists('menus', 'idx_menus_tenant_id');
        $dropIfExists('menus', 'idx_menus_category_id');
        $dropIfExists('menus', 'idx_menus_is_available');
        $dropIfExists('menus', 'idx_menus_tenant_available');
        $dropIfExists('categories', 'idx_categories_tenant_id');
        $dropIfExists('users', 'idx_users_role_id');
        $dropIfExists('users', 'idx_users_company_code');
        $dropIfExists('users', 'idx_users_role');
        $dropIfExists('tenants', 'idx_tenants_company_code');
        $dropIfExists('tenants', 'idx_tenants_status_deleted');
        $dropIfExists('subscriptions', 'idx_subscriptions_tenant_id');
        $dropIfExists('subscriptions', 'idx_subscriptions_status');
        $dropIfExists('activity_logs', 'idx_activity_logs_user_id');
        $dropIfExists('activity_logs', 'idx_activity_logs_created_at');
        $dropIfExists('error_logs', 'idx_error_logs_resolved_status');
        $dropIfExists('error_logs', 'idx_error_logs_created_at');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            return Schema::hasIndex($table, $indexName);
        } catch (\Throwable $e) {
            $connection = \DB::connection();
            $driver = $connection->getDriverName();

            if ($driver === 'sqlite') {
                $results = $connection->select("PRAGMA index_list(`{$table}`)");
                foreach ($results as $row) {
                    if (isset($row->name) && $row->name === $indexName) {
                        return true;
                    }
                }
                return false;
            }

            if ($driver === 'mysql') {
                $results = $connection->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
                return count($results) > 0;
            }

            return false;
        }
    }
};
