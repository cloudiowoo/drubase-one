<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\baas_functions\Service\ProjectFunctionManager;
use Drupal\baas_auth\Service\UnifiedPermissionChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Project Function deletion confirmation form.
 */
class ProjectFunctionDeleteForm extends ConfirmFormBase {

  protected array $functionData = [];

  public function __construct(
    protected readonly ProjectFunctionManager $functionManager,
    protected readonly UnifiedPermissionChecker $permissionChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_functions.manager'),
      $container->get('baas_auth.unified_permission_checker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'baas_functions_project_function_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = NULL, ?string $project_id = NULL, ?string $function_id = NULL): array {
    $current_user = $this->currentUser();
    
    // Check permission
    if (!$this->permissionChecker->canAccessProject((int) $current_user->id(), $project_id)) {
      $this->messenger()->addError($this->t('Access denied to this project.'));
      return new RedirectResponse('/user/functions');
    }

    // Store parameters
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);
    $form_state->set('function_id', $function_id);

    // Load function data
    try {
      $this->functionData = $this->functionManager->getFunctionById($function_id);
      $form_state->set('function_data', $this->functionData);
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Function not found: @error', ['@error' => $e->getMessage()]));
      return new RedirectResponse("/tenant/{$tenant_id}/project/{$project_id}/functions");
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    $function_name = $this->functionData['function_name'] ?? 'Unknown';
    return (string) $this->t('Are you sure you want to delete the function "@name"?', ['@name' => $function_name]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('This action cannot be undone. All function data, versions, logs, and environment variables will be permanently deleted.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Delete Function');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText(): string {
    return (string) $this->t('Cancel');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $tenant_id = $this->getRequest()->get('tenant_id');
    $project_id = $this->getRequest()->get('project_id');
    
    return Url::fromRoute('baas_functions.project_manager', [
      'tenant_id' => $tenant_id,
      'project_id' => $project_id,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $current_user = $this->currentUser();
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $function_id = $form_state->get('function_id');
    $function_data = $form_state->get('function_data');

    try {
      // Delete the function
      $this->functionManager->deleteFunction($function_id, (int) $current_user->id());
      
      $this->messenger()->addStatus($this->t('Function "@name" has been deleted successfully.', [
        '@name' => $function_data['function_name'] ?? 'Unknown',
      ]));

      // Redirect to project functions list
      $form_state->setRedirect('baas_functions.project_manager', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]);
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to delete function: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

}