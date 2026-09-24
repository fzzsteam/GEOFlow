# 阿里云 SAE 单应用部署运行时说明

本文说明 GEOFlow 以“一个 SAE 应用、一个最终镜像”运行时的容器拓扑和发布边界。镜像内部由 Supervisor 管理 Web、队列、知识库、AI 任务、Scheduler 和 Reverb；RDS MySQL、Redis 和 NAS 是容器外部依赖。

真正执行数据库 migration、首次安装和向量写入验收前，仍必须在隔离的 RDS MySQL 数据库完成验证。具体检查步骤见 [RDS MySQL 隔离验收手册](RDS_MYSQL_VALIDATION.md)。

## 1. 运行时拓扑

SAE 只创建一个 GEOFlow 应用，例如 `geoflow`，该应用使用 `docker/Dockerfile.prod` 的 `sae-all` target 构建的最终镜像：

```text
外部网关 / WAF / SLB
          │ HTTP、HTTPS、WebSocket
          ▼
唯一的 GEOFlow SAE 应用
┌─────────────────────────────────────────────┐
│ Supervisor                                   │
│ ├── Nginx :80                                │
│ │    ├── PHP 请求 ───────► PHP-FPM :9000    │
│ │    └── /reverb WebSocket ► Reverb :18080  │
│ ├── 普通 Redis Queue Worker                  │
│ ├── AI Quality front/backfill Worker         │
│ ├── AI Optimization Worker                   │
│ ├── Knowledge Queue Worker                    │
│ ├── Laravel Scheduler                         │
│ └── Laravel Reverb                            │
└─────────────────────────────────────────────┘
          │
          ├── RDS MySQL
          ├── Redis
          ├── NAS
          └── 百炼等外部 AI Provider
```

进程和用途如下：

| 容器内进程 | 作用 | 对外端口 |
| --- | --- | --- |
| Nginx | HTTP 入口、静态文件、PHP-FPM 转发、Reverb WebSocket 反向代理 | 80 |
| PHP-FPM | Laravel Web 请求 | 仅容器内 `127.0.0.1:9000` |
| 普通 Worker | `system-updates`、`geoflow`、`distribution`、`theme-replication`、`default` 队列 | 无 |
| AI Quality front/backfill | AI 质量任务 | 无 |
| AI Optimization | AI 优化任务 | 无 |
| Knowledge Worker | 知识库切片、Embedding 等任务 | 无 |
| Scheduler | Laravel 定时任务 | 无 |
| Reverb | Laravel WebSocket | 仅容器内 `127.0.0.1:18080` |

外部网关只需要访问 SAE 应用的 80 端口。Nginx 会把 `/reverb` 的 WebSocket 请求转发到本容器的 `127.0.0.1:18080`，所以不需要再为 Reverb 创建第二个 SAE 应用或第二个外部入口。

这个方案的代价是所有进程共享同一份 SAE CPU、内存和副本数：不能单独扩容知识库 Worker，也不能只重启 Scheduler。初期建议保持 SAE 单副本；如果以后扩容到多个副本，必须额外验证 Scheduler 的分布式锁、队列幂等性和 Reverb 的多副本广播，否则同一个定时任务可能执行多次。

## 2. 构建唯一 SAE 镜像

`Dockerfile.prod` 默认 target `app-runtime` 仍服务于本地生产 Compose；SAE 工作流使用 `sae-all` target。它在同一条 Docker 构建链中加入 Nginx、Supervisor 和统一入口，不再构建或部署 `-web` 镜像：

```bash
docker buildx build \
  --platform linux/amd64 \
  --target sae-all \
  -f docker/Dockerfile.prod \
  -t <acr-registry>/<namespace>/geoflow:<git-sha> \
  -t <acr-registry>/<namespace>/geoflow:latest \
  --push .
```

GitHub Actions 使用相同的 `sae-all` target，同时推送 commit SHA 和 `latest` 两个 tag；自动部署仍只引用 commit SHA tag，以便追溯和回滚。不要把密码、AccessKey、`APP_KEY` 或完整 `.env` 文件复制进镜像；ACR 地址、应用 ID 和运行时 Secret 通过 CI/CD 或 SAE 配置维护。

## 3. SAE 环境变量与 Secret

SAE 不需要挂载 `.env` 文件。为唯一应用注入非敏感配置，并把密码和 Provider 密钥放进 SAE Secret。仓库提供了可复制到 SAE 控制台的 [`.env.sae.example`](../../.env.sae.example)。

核心配置示例：

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:<固定且随机的 Laravel 密钥>
APP_URL=https://<正式域名>
SITE_URL=https://<正式域名>
GEOFLOW_SAE_RUNTIME=true
GEOFLOW_ALLOW_MISSING_ENV_FILE=true

SAE_ROLE=all
AUTO_WAIT_FOR_DB=true
AUTO_FIX_STORAGE_PERMISSIONS=true

# 普通发布默认关闭；首次部署或明确的数据库发布窗口再打开。
SAE_RELEASE_CONFIRM=false
AUTO_MIGRATE=false
AUTO_INSTALL_ONCE=false
AUTO_OPTIMIZE=false

DB_CONNECTION=mysql
DB_HOST=<RDS MySQL 私网地址>
DB_PORT=3306
DB_DATABASE=<GEOFlow 专用数据库>
DB_USERNAME=<RDS 账号>
DB_PASSWORD=<放入 SAE Secret>

REDIS_CLIENT=phpredis
REDIS_HOST=<Redis 私网地址>
REDIS_PORT=6379
REDIS_PASSWORD=<放入 SAE Secret；无密码时留空>
QUEUE_CONNECTION=redis
CACHE_STORE=redis

FILESYSTEM_DISK=local
GEOFLOW_NGINX_PRIMARY_HOST=<正式域名>
GEOFLOW_NGINX_PRIMARY_ALIASES=<可选的别名域名>
GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN=<托管站点根域名；不用时填 invalid>
GEOFLOW_NGINX_PUBLIC_SCHEME=https
GEOFLOW_NGINX_PUBLIC_PORT=443
GEOFLOW_PHP_FPM_UPSTREAM=127.0.0.1:9000
GEOFLOW_REVERB_UPSTREAM=127.0.0.1:18080
GEOFLOW_NGINX_RESOLVER=<SAE/VPC DNS resolver>

REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=18080
```

`APP_KEY` 必须固定，不能让容器每次重启时重新生成。`DB_PASSWORD`、`REDIS_PASSWORD`、邮件凭据和各 AI Provider 的密钥都放在 SAE Secret；不要写进仓库或 Dockerfile。

将 NAS 挂载到 `/var/www/html/storage`，让 `local` 和 `public` 磁盘的文件根目录落在持久化目录中，并保留当前代码依赖的 POSIX 文件路径、压缩包和临时文件语义。当前 SAE 方案不需要配置 OSS。

## 4. 统一容器启动

最终镜像默认使用：

```text
ENTRYPOINT ["/usr/local/bin/geoflow-entrypoint-sae"]
CMD ["--role=all"]
```

`--role=all` 会依次完成以下动作：

1. 创建运行时目录和 `public/storage` 链接；
2. 等待 RDS MySQL 可连接；
3. 渲染 Nginx 配置，将 PHP-FPM 和 Reverb 指向本容器；
4. 按需执行一次启动前 release 动作；
5. 启动 Supervisor，由 Supervisor 管理 Nginx、PHP-FPM、各个 Worker、Scheduler 和 Reverb。

Supervisor 将子进程日志写到容器标准输出/错误输出。任何 Worker 异常退出都会被自动拉起；Supervisor 自身退出则由 SAE 重启整个容器。SAE 健康检查只需要检查 80 端口的 `/up`，不需要把 PHP-FPM 或 Reverb 暴露为外部端口。

不要在 SAE 控制台覆盖镜像默认启动命令为旧的 `--role=web`，否则会只启动 Web，不会启动后台任务。除非做故障隔离诊断，否则唯一应用必须保持 `SAE_ROLE=all`。

## 5. 首次安装与数据库迁移

单应用模式不再创建独立的 `release` SAE 应用。启动前 release 动作由同一个容器按环境变量控制：

```env
SAE_ROLE=all
SAE_RELEASE_CONFIRM=true
AUTO_MIGRATE=true
AUTO_INSTALL_ONCE=false
AUTO_OPTIMIZE=true
```

如果目标是明确的 GEOFlow 专用空库，首次安装时才额外设置：

```env
AUTO_INSTALL_ONCE=true
```

完成首次安装后，应把 `AUTO_INSTALL_ONCE` 改回 `false`。`AUTO_MIGRATE=true` 会在容器每次启动前检查并执行尚未运行的 migration，因此上线前必须先在隔离 RDS MySQL 完成兼容性验证和备份；确认迁移完成后，可以把它改回 `false`，由后续受控发布窗口临时打开。

如果打开了任意 `AUTO_MIGRATE`、`AUTO_INSTALL_ONCE` 或 `AUTO_OPTIMIZE`，但没有同时设置 `SAE_RELEASE_CONFIRM=true`，容器会主动退出，避免误执行数据库操作。不要把 `AUTO_INSTALL_ONCE=true` 配置到已有业务库。

## 6. 健康检查

容器内健康检查脚本是 `deploy-scripts/sae-healthcheck.sh`。统一模式执行：

```bash
SAE_ROLE=all /usr/local/bin/sae-healthcheck.sh
```

它会检查：

- Nginx 的 `/up` HTTP 响应；
- Nginx、PHP-FPM、普通队列、AI Worker、Knowledge Worker、Scheduler、Reverb 进程；
- RDS MySQL 连接和 migration 状态；
- Redis PING。

如数据库迁移尚未完成，健康检查应保持失败，不要通过 `SAE_HEALTHCHECK_ALLOW_PENDING_MIGRATIONS=true` 长期掩盖发布顺序问题。该变量只适合明确的迁移窗口诊断。

## 7. 本地 Compose 兼容边界

本次单应用运行时不会改变本地 Compose 的服务名和挂载方式：

- `docker-compose.prod.yml` 仍使用 `app-runtime` 默认 target；
- 本地 Compose 仍可把 `.env.prod` 挂载为 `/var/www/html/.env`；
- Nginx 模板仍保留 `app:9000` / `reverb:18080` 作为 Compose 兼容默认值；
- SAE 统一入口会把它们渲染为 `127.0.0.1:9000` / `127.0.0.1:18080`；

## 8. 当前必须在阿里云验收的内容

本地静态检查可以验证 shell 语法、Dockerfile target、Supervisor 配置和 Nginx 模板，但以下事项必须在你的阿里云环境验收：

- SAE 到 RDS MySQL、Redis、NAS 的 VPC、安全组、挂载权限；
- RDS MySQL 向量能力对应的内核版本、维度和实际索引查询；
- ACR 私有镜像拉取权限与 `linux/amd64` 镜像启动；
- 唯一 SAE 应用的 80 端口、`/up` 健康检查和滚动发布行为；
- 外部网关到 Nginx 的 HTTPS、可信代理和 WebSocket Upgrade 转发；
- Reverb 本地 18080 监听和 `/reverb` 反向代理；
- 真实数据库迁移、现有数据升级以及首次安装门禁；
- 单副本下所有后台任务能被消费，且 CPU/内存足够承载 Web 与 Worker 的并发。
