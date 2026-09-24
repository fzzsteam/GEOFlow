# 使用 GitHub Actions 部署 GEOFlow 到 SAE

`.github/workflows/deploy-sae.yml` 参考 `/home/yuanjiawei/AIProject/fzzs/case_site` 的发布链路，先执行 PHP/JavaScript 测试与格式检查，再使用 `docker/Dockerfile.prod` 的 `sae-all` target 构建一个包含 Nginx、PHP-FPM、Supervisor 和全部后台进程的最终镜像，向 ACR 同时推送 commit SHA 与 `latest` 标签，最后只调用一次 `sae DeployApplication`，并使用 commit SHA 标签发布到唯一的 GEOFlow SAE 应用。

这个工作流不再创建或更新 Web、Worker、Knowledge、Scheduler、Reverb 等多个 SAE 应用。它们都是同一个容器内的 Supervisor 子进程。

RDS 向量、迁移和首次安装验收步骤见 [RDS MySQL 隔离验收手册](RDS_MYSQL_VALIDATION.md)。

## 触发方式

| 触发方式 | 默认行为 |
| --- | --- |
| 推送到 `main` | 构建一个 commit 镜像，并部署到唯一的 SAE 应用 |
| `workflow_dispatch` | 默认构建、推送并部署；取消 `deploy_app` 时仍会构建并推送镜像，只跳过 SAE 部署 |

GitHub Runner 默认使用以下公开网络入口登录并推送 ACR：

```text
crpi-7ajeyduewy90avu4.cn-shenzhen.personal.cr.aliyuncs.com/fzzs/geoflow
```

构建会推送两个 tag：

```text
<ACR_LOGIN_REGISTRY>/<ACR_NAMESPACE>/<ACR_REPOSITORY>:<GITHUB_SHA>
<ACR_LOGIN_REGISTRY>/<ACR_NAMESPACE>/<ACR_REPOSITORY>:latest
```

SAE 自动部署仍使用 commit SHA tag，不依赖可变的 `latest`：

```text
<ACR_IMAGE_REGISTRY>/<ACR_NAMESPACE>/<ACR_REPOSITORY>:<GITHUB_SHA>
```

工作流不再推送或部署 `${GITHUB_SHA}-web`，也不再推送一个仅供 Worker 使用的第二个 SAE 镜像。

## GitHub Variables

可在 GitHub 仓库的 **Settings → Secrets and variables → Actions → Variables** 配置，或使用已授权给该仓库的组织级 Actions Variables。当前工作流默认使用上面的 ACR 地址和 `fzzs/geoflow`；可用 Variables 会覆盖默认值。

| Variable | 必填 | 默认值/示例 | 用途 |
| --- | --- | --- | --- |
| `ACR_LOGIN_REGISTRY` | 否 | `crpi-7ajeyduewy90avu4.cn-shenzhen.personal.cr.aliyuncs.com` | GitHub-hosted runner 登录并推送镜像的 ACR 地址 |
| `ACR_IMAGE_REGISTRY` | 否 | `crpi-7ajeyduewy90avu4-vpc.cn-shenzhen.personal.cr.aliyuncs.com` | SAE 通过 VPC 拉取镜像使用的地址 |
| `ACR_NAMESPACE` | 否 | `fzzs` | ACR 命名空间，默认 `fzzs` |
| `ACR_REPOSITORY` | 否 | `geoflow` | ACR 仓库名，默认 `geoflow` |
| `SAE_REGION_ID` | 否 | `cn-shenzhen` | SAE 区域，默认 `cn-shenzhen` |
| `COMPOSER_PACKAGIST_MIRROR` | 否 | `https://mirrors.aliyun.com/composer/` | Docker 构建时可选的 Composer 镜像源 |

`ACR_IMAGE_REGISTRY` 默认使用上述 VPC 地址；如果网络拓扑不同，可将它设置为同一 ACR 实例对应的 SAE 可达地址。GitHub Runner 始终使用 `ACR_LOGIN_REGISTRY` 登录和推送，避免尝试访问仅 VPC 内可达的登录入口。

## GitHub Secrets

在仓库 **Settings → Secrets and variables → Actions → Secrets** 中配置以下 Secrets，或使用已授权给该仓库的组织级 Secrets：

| Secret | 用途 |
| --- | --- |
| `ACR_USERNAME` | ACR 登录账号 |
| `ACR_PASSWORD` | ACR 登录密码或访问凭证 |
| `ALIYUN_SAE_AK_ID` | 调用 SAE API 的阿里云 AccessKey ID |
| `ALIYUN_SAE_AK_SECRET` | 调用 SAE API 的阿里云 AccessKey Secret |
| `SAE_PROD_APP_ID` | 唯一 GEOFlow SAE 应用 ID；工作流将它注入内部变量 `SAE_APP_ID` |

当前 workflow 没有关联 GitHub Environment，因此 Environment 专属 Secrets 不会自动提供给它。若使用 Environment 专属 Secrets，需要同时在 workflow job 上声明对应的 `environment:`。

旧的 `SAE_WEB_APP_ID`、`SAE_WORKER_APP_ID`、`SAE_KNOWLEDGE_APP_ID`、`SAE_SCHEDULER_APP_ID`、`SAE_REVERB_APP_ID` 和 `SAE_RELEASE_APP_ID` 不会被新的工作流读取。建议确认新应用可以正常启动后，再删除旧的多个 SAE 应用和对应 Secrets。

## 唯一 SAE 应用准备

只需要在 SAE 控制台预先创建一个应用，例如 `geoflow`，然后配置：

| 配置项 | 建议值 |
| --- | --- |
| 镜像 | 由工作流发布的 `${GITHUB_SHA}` |
| 容器端口 | `80` |
| 启动命令 | 使用镜像默认 `ENTRYPOINT`/`CMD`，不要覆盖为旧的 `--role=web` |
| `SAE_ROLE` | `all` |
| 健康检查 | `GET /up`，端口 80 |
| 副本数 | 初期 1 |
| VPC/安全组 | 能访问 RDS MySQL、Redis、NAS 和 ACR |
| NAS | 按需挂载到 `/var/www/html/storage` |

镜像启动后，Supervisor 会同时拉起：

| 容器内进程 | 命令/职责 |
| --- | --- |
| Nginx + PHP-FPM | Web 请求，Nginx 监听 80，PHP-FPM 监听 127.0.0.1:9000 |
| 普通 Worker | `queue:work redis --queue=system-updates,geoflow,distribution,theme-replication,default ...` |
| AI Quality | `geoflow:work-ai-quality front/backfill` |
| AI Optimization | `geoflow:work-ai-optimization` |
| Knowledge | `queue:work redis --queue=knowledge ...` |
| Scheduler | `schedule:work` |
| Reverb | `reverb:start`，监听容器内 127.0.0.1:18080 |

外部网关只需要将普通 HTTP 和 WebSocket 请求转发到 SAE 的 80 端口。Nginx 的 `/reverb` 规则会把 WebSocket 转发到本容器内的 Reverb，不需要单独的 Reverb SAE 应用。

## 数据库初始化和迁移

工作流自身不执行 `migrate`、`geoflow:install` 或任何清库操作。统一容器只在明确开启相应环境变量时执行启动前 release 动作：

```env
SAE_RELEASE_CONFIRM=true
AUTO_MIGRATE=true
AUTO_INSTALL_ONCE=false
AUTO_OPTIMIZE=true
```

目标是空的 GEOFlow 专用数据库时，首次安装才额外设置 `AUTO_INSTALL_ONCE=true`。完成后把它改回 `false`。如果任意 release action 被打开但 `SAE_RELEASE_CONFIRM` 不是 `true`，容器会主动退出，不会启动常驻进程。

在生产发布前，应先完成 RDS MySQL 隔离验收、备份和回滚预案，再在 SAE 环境变量中开启 release action 并部署。迁移完成后，可以关闭 `AUTO_MIGRATE` 和 `AUTO_OPTIMIZE`，保持普通滚动发布只更新镜像。

## 验证与回滚

1. 在 ACR 中确认 `${GITHUB_SHA}` 唯一镜像存在，并确认 SAE 能从 `ACR_IMAGE_REGISTRY` 拉取。
2. 第一次部署时只保持 SAE 单副本，确认容器日志中 Nginx、PHP-FPM、各类 Worker、Scheduler 和 Reverb 都已启动。
3. 访问 `/up`，检查 RDS MySQL、Redis、NAS 连接和后台任务消费。
4. 通过外部网关验证普通 HTTPS、登录、知识库切片和 WebSocket 握手。
5. 确认稳定后再考虑扩容。扩容前需要验证 Scheduler 防重、队列幂等和 Reverb 多副本广播。
6. 发布异常时在 SAE 控制台回滚到上一条 commit tag；不要通过覆盖 `latest` 来回滚。

## 风险清单

- **资源竞争**：Web、AI、知识库和队列任务共享同一 SAE CPU/内存，重任务可能影响页面响应；需要通过 Worker 的并发和队列参数控制。
- **单点故障**：容器或 Supervisor 故障会同时影响 Web 和后台任务；初期接受单应用简化，后续有高可用要求时再拆分应用。
- **多副本重复调度**：统一容器的每个副本都会运行 Scheduler，扩容前必须确认 Redis 分布式锁和任务幂等。
- **WebSocket 路由**：外部网关必须保留 Upgrade/Connection 头，Nginx 才能把 `/reverb` 正确转给 Reverb。
- **ACR 网络风险**：GitHub Runner 不能访问只在 VPC 内可达的登录地址；登录地址和 SAE 拉取地址必须按网络边界分别配置。
- **凭证风险**：当前使用最小权限 RAM 用户、GitHub Secrets 和 SAE Secret；不要把数据库、Redis 或 AI 密钥写入 Dockerfile。
- **数据库版本风险**：镜像发布成功不等于 MySQL 方言和向量 migration 已兼容；数据库迁移仍需先完成隔离库验证。
