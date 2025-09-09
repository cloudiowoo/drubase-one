<?php

namespace Drupal\baas_api\Controller;

use Drupal\Core\Url;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\baas_api\Service\ApiTokenManager;

/**
 * API令牌控制器。
 */
class ApiTokenController extends BaseApiController {

  /**
   * API令牌管理器。
   *
   * @var \Drupal\baas_api\Service\ApiTokenManager
   */
  protected $tokenManager;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_api\Service\ApiResponseService $response_service
   *   API响应服务.
   * @param \Drupal\baas_api\Service\ApiValidationService $validation_service
   *   API验证服务.
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器.
   * @param \Drupal\baas_api\Service\ApiTokenManager $token_manager
   *   API令牌管理器。
   */
  public function __construct(
    ApiResponseService $response_service,
    ApiValidationService $validation_service,
    UnifiedPermissionCheckerInterface $permission_checker,
    ApiTokenManager $token_manager
  ) {
    parent::__construct($response_service, $validation_service, $permission_checker);
    $this->tokenManager = $token_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_api.response'),
      $container->get('baas_api.validation'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('baas_api.token_manager')
    );
  }

  /**
   * 撤销令牌。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $token_hash
   *   令牌哈希。
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   重定向响应。
   */
  public function revokeToken($tenant_id, $token_hash) {
    $result = $this->tokenManager->revokeToken($token_hash, $tenant_id);

    if ($result) {
      $this->messenger()->addStatus($this->t('令牌已成功撤销'));
    }
    else {
      $this->messenger()->addError($this->t('撤销令牌时出错'));
    }

    // 重定向回令牌列表
    $url = Url::fromRoute('baas_api.tokens', [
      'tenant_id' => $tenant_id,
    ])->toString();

    return new RedirectResponse($url);
  }
}
