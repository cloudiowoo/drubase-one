<?php

declare(strict_types=1);

namespace Drupal\baas_project\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_auth\Service\UserTenantMappingInterface;

/**
 * 用户项目成员管理表单。
 */
class UserProjectMemberForm extends FormBase
{

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_project\ProjectManagerInterface $projectManager
   *   项目管理服务。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenantManager
   *   租户管理服务。
   * @param \Drupal\baas_auth\Service\UserTenantMappingInterface $userTenantMapping
   *   用户租户映射服务。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly TenantManagerInterface $tenantManager,
    protected readonly UserTenantMappingInterface $userTenantMapping
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('baas_tenant.manager'),
      $container->get('baas_auth.user_tenant_mapping')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_project_user_member_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $project_id = NULL): array
  {
    if (!$project_id) {
      $this->messenger()->addError($this->t('项目ID参数缺失。'));
      return [];
    }

    // 获取项目信息
    $project = $this->projectManager->getProject($project_id);
    if (!$project) {
      $this->messenger()->addError($this->t('项目不存在。'));
      return [];
    }

    // 获取租户信息
    $tenant = $this->tenantManager->getTenant($project['tenant_id']);
    if (!$tenant) {
      $this->messenger()->addError($this->t('租户不存在。'));
      return [];
    }

    $form['project_id'] = [
      '#type' => 'value',
      '#value' => $project_id,
    ];

    // 项目信息显示
    $form['project_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('项目信息'),
      '#collapsible' => FALSE,
    ];

    $form['project_info']['display'] = [
      '#type' => 'item',
      '#markup' => '<p><strong>' . $this->t('项目名称') . ':</strong> ' . $project['name'] . '</p>' .
        '<p><strong>' . $this->t('所属租户') . ':</strong> ' . $tenant['name'] . '</p>',
    ];

    // 当前成员列表
    $form['current_members'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('当前成员'),
      '#collapsible' => FALSE,
    ];

    try {
      $members = $this->projectManager->getProjectMembers($project_id);

      if (!empty($members)) {
        // 创建成员管理表格
        $header = [
          'name' => $this->t('用户名'),
          'email' => $this->t('邮箱'),
          'role' => $this->t('当前角色'),
          'new_role' => $this->t('新角色'),
          'operations' => $this->t('操作'),
        ];

        $form['current_members']['table'] = [
          '#type' => 'table',
          '#header' => $header,
          '#empty' => $this->t('暂无成员'),
        ];

        // 定义可用角色
        $role_options = [
          'member' => $this->t('成员'),
          'editor' => $this->t('编辑者'),
          'admin' => $this->t('管理员'),
        ];

        $owner_uid = $project['owner_uid'] ?? 0;
        $current_user_id = $this->currentUser()->id();

        foreach ($members as $index => $member) {
          $user_id = $member['user_id'];
          $is_owner = ($user_id == $owner_uid);
          $is_current_user = ($user_id == $current_user_id);

          // 显示名称
          $display_name = $member['display_name'] ?? $member['name'] ?? $this->t('未知用户');
          if (!is_string($display_name)) {
            $display_name = (string) $display_name;
          }

          // 角色
          $role = $member['role'] ?? $this->t('未知角色');
          if (!is_string($role)) {
            $role = (string) $role;
          }

          $form['current_members']['table'][$index]['name'] = [
            '#markup' => $display_name . ($is_owner ? ' (' . $this->t('拥有者') . ')' : ''),
          ];

          $form['current_members']['table'][$index]['email'] = [
            '#markup' => $member['email'] ?? '',
          ];

          $form['current_members']['table'][$index]['role'] = [
            '#markup' => $role,
          ];

          // 项目拥有者无法修改角色
          if ($is_owner) {
            $form['current_members']['table'][$index]['new_role'] = [
              '#markup' => $this->t('拥有者'),
            ];
            $form['current_members']['table'][$index]['operations'] = [
              '#markup' => $this->t('不可操作'),
            ];
          } else {
            // 存储用户ID用于提交处理
            $form['current_members']['table'][$index]['user_id'] = [
              '#type' => 'value',
              '#value' => $user_id,
            ];

            // 角色下拉选择
            $form['current_members']['table'][$index]['new_role'] = [
              '#type' => 'select',
              '#title' => $this->t('设置新角色'),
              '#title_display' => 'invisible',
              '#options' => $role_options,
              '#default_value' => $role,
            ];

            // 操作按钮
            $form['current_members']['table'][$index]['operations'] = [
              '#type' => 'actions',
              'update' => [
                '#type' => 'submit',
                '#value' => $this->t('更新角色'),
                '#name' => 'update_role_' . $user_id,
                '#submit' => ['::updateMemberRole'],
                '#attributes' => [
                  'class' => ['button--small'],
                ],
              ],
              'remove' => [
                '#type' => 'submit',
                '#value' => $this->t('移除'),
                '#name' => 'remove_member_' . $user_id,
                '#submit' => ['::removeMember'],
                '#attributes' => [
                  'class' => ['button--danger', 'button--small'],
                ],
              ],
            ];

            // 当前用户不能移除自己
            if ($is_current_user) {
              $form['current_members']['table'][$index]['operations']['remove']['#attributes']['disabled'] = TRUE;
              $form['current_members']['table'][$index]['operations']['remove']['#value'] = $this->t('无法移除自己');
            }
          }
        }
      } else {
        $form['current_members']['empty'] = [
          '#type' => 'item',
          '#markup' => '<p>' . $this->t('暂无成员') . '</p>',
        ];
      }
    } catch (\Exception $e) {
      $form['current_members']['error'] = [
        '#type' => 'item',
        '#markup' => '<p>' . $this->t('无法加载成员列表：@error', ['@error' => $e->getMessage()]) . '</p>',
      ];
    }

    // 添加成员
    $form['add_member'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('添加成员'),
      '#collapsible' => FALSE,
    ];

    $form['add_member']['user_email'] = [
      '#type' => 'email',
      '#title' => $this->t('用户邮箱'),
      '#description' => $this->t('输入要添加的用户邮箱地址。'),
    ];

    $form['add_member']['role'] = [
      '#type' => 'select',
      '#title' => $this->t('角色'),
      '#options' => [
        'member' => $this->t('成员'),
        'editor' => $this->t('编辑者'),
        'admin' => $this->t('管理员'),
      ],
      '#default_value' => 'member',
    ];

    // 操作按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('添加成员'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('返回项目详情'),
      '#url' => Url::fromRoute('baas_project.user_view', [
        'tenant_id' => $project['tenant_id'],
        'project_id' => $project_id
      ]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    // 仅当点击"添加成员"按钮时才验证邮箱
    if ($form_state->getTriggeringElement()['#id'] === 'edit-submit') {
      $email = $form_state->getValue('user_email');

      if (empty($email)) {
        $form_state->setErrorByName('user_email', $this->t('请输入用户邮箱。'));
        return;
      }

      // 检查用户是否存在
      $user_storage = \Drupal::entityTypeManager()->getStorage('user');
      $users = $user_storage->loadByProperties(['mail' => $email]);

      if (empty($users)) {
        $form_state->setErrorByName('user_email', $this->t('用户不存在。'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $project_id = $form_state->getValue('project_id');
    $email = $form_state->getValue('user_email');
    $role = $form_state->getValue('role');

    try {
      // 获取用户
      $user_storage = \Drupal::entityTypeManager()->getStorage('user');
      $users = $user_storage->loadByProperties(['mail' => $email]);
      $user = reset($users);

      if (!$user) {
        throw new \Exception($this->t('用户不存在')->__toString());
      }

      $user_id = (int) $user->id();

      // 检查用户是否已经是项目成员
      if ($this->projectManager->isProjectMember($project_id, $user_id)) {
        $this->messenger()->addWarning($this->t('用户 @email 已经是该项目的成员。', ['@email' => $email]));
        return;
      }

      // 添加项目成员
      $success = $this->projectManager->addProjectMember($project_id, $user_id, $role);

      if ($success) {
        $this->messenger()->addStatus($this->t('成功添加成员 @email，角色：@role', [
          '@email' => $email,
          '@role' => $role,
        ]));
      } else {
        throw new \Exception($this->t('添加成员失败')->__toString());
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('添加成员失败：@error', ['@error' => $e->getMessage()]));
    }

    // 刷新页面
    $form_state->setRebuild(TRUE);
  }

  /**
   * 更新成员角色的提交处理。
   *
   * @param array $form
   *   表单数组。
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   表单状态。
   */
  public function updateMemberRole(array &$form, FormStateInterface $form_state): void
  {
    $project_id = $form_state->getValue('project_id');
    $triggering_element = $form_state->getTriggeringElement();
    $element_name = $triggering_element['#name'];

    // 从按钮名称中提取用户ID
    preg_match('/update_role_(\d+)/', $element_name, $matches);
    if (empty($matches[1])) {
      $this->messenger()->addError($this->t('无法识别用户ID。'));
      return;
    }

    $user_id = (int) $matches[1];

    // 直接从触发元素的父元素获取新角色值
    $parents = $triggering_element['#parents'];
    $role_parents = [];

    // 我们需要获取同一行的新角色值
    // 按钮的路径通常是 ['current_members']['table'][0]['operations']['update']
    // 角色的路径应该是 ['current_members']['table'][0]['new_role']
    if (count($parents) >= 4) {
      $role_parents = array_merge(
        array_slice($parents, 0, count($parents) - 2),
        ['new_role']
      );
    }

    $new_role = '';
    if (!empty($role_parents)) {
      $new_role = $form_state->getValue($role_parents);
    }

    // 如果无法从父元素获取，尝试直接遍历所有表单值
    if (empty($new_role)) {
      // 获取当前用户的角色
      $current_role = $this->projectManager->getUserProjectRole($project_id, $user_id);
      if ($current_role) {
        $new_role = $current_role; // 如果无法获取新角色，使用当前角色
      } else {
        $new_role = 'member'; // 默认角色
      }

      // 尝试从表单状态中查找值
      $all_values = $form_state->getValues();
      if (
        isset($all_values['current_members']) &&
        isset($all_values['current_members']['table'])
      ) {
        foreach ($all_values['current_members']['table'] as $index => $row) {
          if (isset($row['user_id']) && $row['user_id'] == $user_id && isset($row['new_role'])) {
            $new_role = $row['new_role'];
            break;
          }
        }
      }

      // 如果仍然无法获取，尝试从表单中直接获取
      if (empty($new_role) || $new_role === $current_role) {
        foreach ($form['current_members']['table'] as $index => $row) {
          if (
            is_numeric($index) &&
            isset($row['user_id']['#value']) &&
            $row['user_id']['#value'] == $user_id &&
            isset($row['new_role']['#default_value'])
          ) {
            $new_role = $row['new_role']['#default_value'];
            break;
          }
        }
      }
    }

    if (empty($new_role)) {
      $this->messenger()->addError($this->t('无法确定要设置的新角色。'));
      return;
    }

    try {
      // 更新成员角色
      $success = $this->projectManager->updateMemberRole($project_id, $user_id, $new_role);

      if ($success) {
        $this->messenger()->addStatus($this->t('已成功更新用户角色为 @role。', [
          '@role' => $new_role,
        ]));
      } else {
        $this->messenger()->addError($this->t('更新用户角色失败。'));
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('更新用户角色失败：@error', ['@error' => $e->getMessage()]));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * 移除成员的提交处理。
   *
   * @param array $form
   *   表单数组。
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   表单状态。
   */
  public function removeMember(array &$form, FormStateInterface $form_state): void
  {
    $project_id = $form_state->getValue('project_id');
    $triggering_element = $form_state->getTriggeringElement();
    $element_name = $triggering_element['#name'];

    // 从按钮名称中提取用户ID
    preg_match('/remove_member_(\d+)/', $element_name, $matches);
    if (empty($matches[1])) {
      $this->messenger()->addError($this->t('无法识别用户ID。'));
      return;
    }

    $user_id = (int) $matches[1];
    $current_user_id = (int) $this->currentUser()->id();

    // 防止用户移除自己
    if ($user_id === $current_user_id) {
      $this->messenger()->addError($this->t('您不能移除自己的成员资格。'));
      return;
    }

    try {
      // 移除项目成员
      $success = $this->projectManager->removeProjectMember($project_id, $user_id);

      if ($success) {
        $this->messenger()->addStatus($this->t('已成功移除该成员。'));
      } else {
        $this->messenger()->addError($this->t('移除成员失败。'));
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('移除成员失败：@error', ['@error' => $e->getMessage()]));
    }

    $form_state->setRebuild(TRUE);
  }
}
