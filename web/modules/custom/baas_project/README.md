# BaaS项目管理模块

## 模块概述

BaaS项目管理模块提供了多租户环境下的项目管理功能，支持创建、编辑、删除项目，管理项目成员和权限，以及项目资源使用统计。

## 角色系统说明

本系统包含两套独立但相关的角色系统：

### 1. 系统级角色（由baas_tenant模块管理）

- **project_manager**：系统级角色，授予用户创建和管理项目的权限。用户必须先被提升为租户（具有此角色），才能创建和管理租户级别的项目。

### 2. 项目级角色（由baas_project模块管理）

项目内部角色决定用户在特定项目中的权限级别：

- **project_viewer**（项目查看者）：只有基本的项目查看权限
- **project_editor**（项目编辑者）：具有项目内容编辑和创建权限
- **project_admin**（项目管理员）：具有项目内的管理权限，但权限范围限于特定项目
- **project_owner**（项目拥有者）：拥有项目的完全控制权，包括项目设置管理

### 角色区别

- **project_manager** 是系统级角色，用户可以创建多个项目
- **project_admin** 是项目级角色，用户只在特定项目中拥有管理员权限

## 模块功能

- 项目创建和管理
- 项目成员管理
- 项目权限控制
- 项目资源使用统计
- 项目所有权转移

## 数据结构

- baas_project_config：项目配置表
- baas_project_members：项目成员表
- baas_project_usage：项目资源使用统计表

## API接口

模块提供以下主要服务：

- ProjectManagerInterface：项目管理服务
- ProjectUsageTrackerInterface：项目使用统计服务
- ProjectMemberManagerInterface：项目成员管理服务

## 项目权限

模块定义了以下权限：

- view baas project：查看项目
- create baas project：创建项目
- edit baas project：编辑项目
- delete baas project：删除项目
- manage baas project members：管理项目成员
- access baas project content：访问项目内容
- create baas project content：创建项目内容
- edit baas project content：编辑项目内容
- delete baas project content：删除项目内容
- manage baas project settings：管理项目设置
