<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\baas_auth\Authentication\BaasAuthenticatedUser;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * BaaS认证用户权限检查测试。
 *
 * @coversDefaultClass \Drupal\baas_auth\Authentication\BaasAuthenticatedUser
 * @group baas_api
 * @group baas_auth
 */
class BaasAuthenticatedUserTest extends UnitTestCase
{

  /**
   * Mock统一权限检查器。
   *
   * @var \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected UnifiedPermissionCheckerInterface|MockObject $permissionChecker;

  /**
   * 测试用载荷数据。
   *
   * @var array
   */
  protected array $testPayload;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // 创建Mock权限检查器
    $this->permissionChecker = $this->createMock(UnifiedPermissionCheckerInterface::class);

    // 设置测试载荷
    $this->testPayload = [
      'sub' => '123',
      'tenant_id' => 'tenant_test_123',
      'project_id' => 'project_test_456',
      'username' => 'test_user',
      'email' => 'test@example.com',
      'roles' => ['tenant_user'],
      'permissions' => ['read_entities', 'create_entities'],
      'iat' => time(),
      'exp' => time() + 3600,
    ];
  }

  /**
   * 测试基本用户信息获取。
   *
   * @covers ::__construct
   * @covers ::id
   * @covers ::getTenantId
   * @covers ::getProjectId
   * @covers ::getAccountName
   * @covers ::getEmail
   */
  public function testBasicUserInfo(): void
  {
    $user = new BaasAuthenticatedUser($this->testPayload, $this->permissionChecker);

    $this->assertEquals('123', $user->id());
    $this->assertEquals('tenant_test_123', $user->getTenantId());
    $this->assertEquals('project_test_456', $user->getProjectId());
    $this->assertEquals('test_user', $user->getAccountName());
    $this->assertEquals('test@example.com', $user->getEmail());
  }

  /**
   * 测试角色权限检查。
   *
   * @covers ::getRoles
   * @covers ::hasRole
   */
  public function testRoleChecking(): void
  {
    $user = new BaasAuthenticatedUser($this->testPayload, $this->permissionChecker);

    $this->assertEquals(['tenant_user'], $user->getRoles());
    $this->assertTrue($user->hasRole('tenant_user'));
    $this->assertFalse($user->hasRole('admin'));
  }

  /**
   * 测试项目级权限检查。
   *
   * @covers ::hasPermission
   * @covers ::hasProjectPermission
   */
  public function testProjectPermissionChecking(): void
  {
    // 配置Mock期望
    $this->permissionChecker
      ->expects($this->once())
      ->method('checkProjectPermission')
      ->with(123, 'project_test_456', 'create_entities')
      ->willReturn(true);

    $user = new BaasAuthenticatedUser($this->testPayload, $this->permissionChecker);

    // 测试通过hasPermission检查项目权限
    $this->assertTrue($user->hasPermission('create_entities'));

    // 测试直接的项目权限检查
    $this->permissionChecker
      ->expects($this->once())
      ->method('checkProjectPermission')
      ->with(123, 'project_test_456', 'delete_entities')
      ->willReturn(false);

    $this->assertFalse($user->hasProjectPermission('delete_entities'));
  }

  /**
   * 测试租户级权限检查。
   *
   * @covers ::hasPermission  
   * @covers ::hasTenantPermission
   */
  public function testTenantPermissionChecking(): void
  {
    // 创建没有项目上下文的载荷
    $tenantPayload = $this->testPayload;
    unset($tenantPayload['project_id']);

    $this->permissionChecker
      ->expects($this->once())
      ->method('checkTenantPermission')
      ->with(123, 'tenant_test_123', 'manage_users')
      ->willReturn(true);

    $user = new BaasAuthenticatedUser($tenantPayload, $this->permissionChecker);

    // 测试通过hasPermission检查租户权限
    $this->assertTrue($user->hasPermission('manage_users'));

    // 测试直接的租户权限检查
    $this->permissionChecker
      ->expects($this->once())
      ->method('checkTenantPermission')
      ->with(123, 'tenant_test_123', 'delete_tenant')
      ->willReturn(false);

    $this->assertFalse($user->hasTenantPermission('delete_tenant'));
  }

  /**
   * 测试实体级权限检查。
   *
   * @covers ::hasEntityPermission
   */
  public function testEntityPermissionChecking(): void
  {
    $this->permissionChecker
      ->expects($this->once())
      ->method('checkProjectEntityPermission')
      ->with(123, 'project_test_456', 'users', 'create')
      ->willReturn(true);

    $user = new BaasAuthenticatedUser($this->testPayload, $this->permissionChecker);

    $this->assertTrue($user->hasEntityPermission('users', 'create'));

    // 测试权限检查失败的情况
    $this->permissionChecker
      ->expects($this->once())
      ->method('checkProjectEntityPermission')
      ->with(123, 'project_test_456', 'users', 'delete')
      ->willReturn(false);

    $this->assertFalse($user->hasEntityPermission('users', 'delete'));
  }

  /**
   * 测试权限检查异常处理。
   *
   * @covers ::hasPermission
   */
  public function testPermissionCheckingExceptionHandling(): void
  {
    $this->permissionChecker
      ->expects($this->once())
      ->method('checkProjectPermission')
      ->with(123, 'project_test_456', 'test_permission')
      ->willThrowException(new \Exception('Database connection failed'));

    $user = new BaasAuthenticatedUser($this->testPayload, $this->permissionChecker);

    // 异常时应该返回false
    $this->assertFalse($user->hasPermission('test_permission'));
  }

  /**
   * 测试无效用户信息的权限检查。
   *
   * @covers ::hasPermission
   */
  public function testPermissionCheckingWithInvalidUserInfo(): void
  {
    // 创建无效的载荷（缺少用户ID）
    $invalidPayload = $this->testPayload;
    unset($invalidPayload['sub']);

    $user = new BaasAuthenticatedUser($invalidPayload, $this->permissionChecker);

    // 无用户ID时应该返回false
    $this->assertFalse($user->hasPermission('any_permission'));

    // 创建无租户ID的载荷
    $noTenantPayload = $this->testPayload;
    unset($noTenantPayload['tenant_id']);

    $user2 = new BaasAuthenticatedUser($noTenantPayload, $this->permissionChecker);

    // 无租户ID时应该返回false
    $this->assertFalse($user2->hasPermission('any_permission'));
  }

  /**
   * 测试认证状态。
   *
   * @covers ::isAuthenticated
   * @covers ::isAnonymous
   */
  public function testAuthenticationState(): void
  {
    $user = new BaasAuthenticatedUser($this->testPayload, $this->permissionChecker);

    $this->assertTrue($user->isAuthenticated());
    $this->assertFalse($user->isAnonymous());
  }

  /**
   * 测试语言和时区设置。
   *
   * @covers ::getPreferredLangcode
   * @covers ::getPreferredAdminLangcode
   * @covers ::getTimeZone
   * @covers ::getLastAccessedTime
   */
  public function testLocalizationSettings(): void
  {
    $user = new BaasAuthenticatedUser($this->testPayload, $this->permissionChecker);

    $this->assertEquals('en', $user->getPreferredLangcode());
    $this->assertEquals('en', $user->getPreferredAdminLangcode());
    $this->assertEquals('UTC', $user->getTimeZone());
    $this->assertEquals($this->testPayload['iat'], $user->getLastAccessedTime());
  }

  /**
   * 测试显示名称。
   *
   * @covers ::getDisplayName
   */
  public function testDisplayName(): void
  {
    $user = new BaasAuthenticatedUser($this->testPayload, $this->permissionChecker);

    $this->assertEquals('test_user', $user->getDisplayName());
  }

  /**
   * 测试载荷数据获取。
   *
   * @covers ::getPayload
   */
  public function testPayloadAccess(): void
  {
    $user = new BaasAuthenticatedUser($this->testPayload, $this->permissionChecker);

    $this->assertEquals($this->testPayload, $user->getPayload());
  }

}