<?php

/**
 * @file
 * 租户管理模块API文档.
 */

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Route;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @defgroup baas_tenant_api BaaS租户管理API
 * @{
 * 租户管理模块提供的API接口与钩子函数。
 *
 * 本模块提供了一套完整的多租户管理API，用于创建、管理和访问BaaS平台中的租户。
 * 租户是BaaS平台中的基本组织单位，每个租户拥有自己独立的数据存储空间、API密钥和权限设置。
 *
 * ## 主要功能
 *
 * - 租户创建与管理
 * - 基于域名、API密钥的租户识别
 * - 租户访问权限控制
 * - 租户资源限制与统计
 *
 * ## 示例用法
 *
 * 1. 创建租户:
 *
 * ```php
 * $tenant_manager = \Drupal::service('baas_tenant.manager');
 * $tenant_id = $tenant_manager->createTenant('租户名称', [
 *   'domain' => 'example.com',
 *   'api_key' => '随机生成的API密钥',
 *   'max_entities' => 1000,
 *   'max_edge_functions' => 100,
 * ]);
 * ```
 *
 * 2. 识别当前租户:
 *
 * ```php
 * $tenant_resolver = \Drupal::service('baas_tenant.resolver');
 * $current_tenant = $tenant_resolver->getCurrentTenant();
 * ```
 *
 * 3. 记录资源使用:
 *
 * ```php
 * $tenant_manager = \Drupal::service('baas_tenant.manager');
 * $tenant_manager->recordUsage($tenant_id, 'api_call', 1);
 * ```
 *
 * @see \Drupal\baas_tenant\TenantManagerInterface
 * @see \Drupal\baas_tenant\TenantResolver
 * @}
