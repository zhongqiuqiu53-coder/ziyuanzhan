# ziyuanzhan - 资源站监测

ziyuanzu.com 资源站实时监测面板。通过 [ziyuanzu API](https://www.ziyuanzu.com/api/v1) 获取资源站数据，定期检测各站点可用性，生成静态 JSON + HTML 页面。

## 工作原理

```
GitHub Actions (每6小时) → monitor.py → fetch ziyuanzu API → 健康检查 → 生成静态文件 → GitHub Pages 自动部署
```

**零服务器、零运行时、零成本。**

> 📖 完整用法（面板、JSON 接口、接 CMS、排错）见 **[使用指南.md](使用指南.md)**

## 静态文件

部署到 GitHub Pages 后，可通过以下 URL 访问：

| 文件 | URL | 说明 |
|------|-----|------|
| `docs/index.html` | `https://zhongqiuqiu53-coder.github.io/ziyuanzhan/` | 可视化监控面板 |
| `data/latest.json` | `.../data/latest.json` | 全部资源站数据 |
| `data/online.json` | `.../data/online.json` | 在线资源站 |
| `data/offline.json` | `.../data/offline.json` | 离线资源站 |
| `data/stats.json` | `.../data/stats.json` | 统计数据 |
| `data/fastest.json` | `.../data/fastest.json` | 响应最快 Top 20 |
| `data/top-rated.json` | `.../data/top-rated.json` | 高评分 Top 20 |
| `data/vod.json` | `.../data/vod.json` | **CMS 采集接口（maccms 标准格式）** |
| `data/vod-online.json` | `.../data/vod-online.json` | CMS 采集接口（仅在线站） |
| `data/apis.json` | `.../data/apis.json` | 采集源清单（含各站 api 字段） |
| `data/apis.csv` | `.../data/apis.csv` | 采集源清单（Excel 可直接打开） |

所有 JSON 接口均带 `Access-Control-Allow-Origin: *`，浏览器可直接 `fetch`。

## 接 CMS（苹果CMS V10 / 海洋CMS / maccms）

CMS 的「采集 → 自定义资源库 / 接口管理」需要的是**苹果CMS 标准接口路径**，
本部署已经按这个形态对外提供，直接复制地址即可：

| CMS 接口类型 | 资源库地址 |
|---|---|
| JSON | `https://zhongqiuqiu53-coder.github.io/ziyuanzhan/api.php/provide/vod/` |
| JSON（带筛选形态） | `.../api.php/provide/vod/at/json/` |
| XML | `.../api.php/provide/vod/at/xml/` |
| JSON（`application/json` 备用） | `.../api.php/provide/vod.json` |

返回体与真实 maccms 接口（`api.php/provide/vod/`）逐字段一致：
顶层 `code/msg/page/pagecount/limit/total/list/class`，条目 `vod_id/vod_name/type_id/vod_play_url/vod_play_from` 等。

静态托管的边界：`?ac=detail&ids=xxx` 返回全量列表而非单条详情，`wd`/`t`/`pg` 服务端筛选不生效。
需要完整动态行为时，把 `cms_api.php` 上传到你的 PHP 主机，
资源库地址改成 `https://你的域名/zy/api.php/provide/vod/`。

各资源站自己的真实影视采集接口在 `apis.csv` / `apis.json` 里，可批量录入 CMS。
详见 [使用指南.md](使用指南.md)。

## AI Agent 使用示例

```python
import requests

# 获取所有在线资源站
resp = requests.get("https://zhongqiuqiu53-coder.github.io/ziyuanzhan/data/online.json")
data = resp.json()
print(f"在线资源站: {data['total']} 个")
for r in data["data"][:5]:
    print(f"  {r['name']} - {r['link']}")

# 统计
stats = requests.get("https://zhongqiuqiu53-coder.github.io/ziyuanzhan/data/stats.json").json()
print(f"在线率: {stats['overview']['onlineRate']}%")

# CMS 采集接口（maccms 标准格式）
vod = requests.get("https://zhongqiuqiu53-coder.github.io/ziyuanzhan/data/vod.json").json()
print(f"可采集条目: {vod['total']} 个")

# 采集源清单
apis = requests.get("https://zhongqiuqiu53-coder.github.io/ziyuanzhan/data/apis.json").json()
for a in apis["data"][:5]:
    print(f"  {a['name']}: {a['api']} ({a['status']})")
```

## 本地运行

```bash
pip install -r requirements.txt
python monitor.py       # 抓数据 + 生成 docs/index.html 与 docs/data/*.json
python export_cms.py   # 追加生成 vod.json / vod-online.json / apis.json / apis.csv
```

生成的文件：
- `docs/index.html` — 监控面板
- `docs/data/*.json` — 各类 JSON 数据
- `docs/data/vod.json` — CMS 采集接口（maccms 标准）
- `docs/data/apis.csv` — 采集源清单

## 项目结构

```
ziyuanzhan/
├── monitor.py               # 监测脚本（核心）
├── export_cms.py            # 导出 CMS 采集格式 + 采集源清单
├── requirements.txt         # Python 依赖
├── 使用指南.md               # 完整用法与对接说明
├── .github/workflows/
│   └── monitor.yml          # GitHub Actions 定时监控 + 部署
└── docs/                    # GitHub Pages 根目录
    ├── index.html           # 静态监控面板
    └── data/
        ├── latest.json      # 全部数据
        ├── online.json      # 在线资源站
        ├── offline.json     # 离线资源站
        ├── stats.json       # 统计数据
        ├── fastest.json     # 最快资源站
        ├── top-rated.json   # 高评分资源站
        ├── vod.json         # CMS 采集接口（maccms 标准）
        ├── vod-online.json  # CMS 采集接口（仅在线站）
        ├── apis.json        # 采集源清单
        ├── apis.csv         # 采集源清单（Excel）
        └── monitor_*.json   # 历史快照
```

## 数据来源

- [ziyuanzu.com](https://www.ziyuanzu.com) API: `https://www.ziyuanzu.com/api/v1/sources`
- 本项目为第三方监测工具，与 ziyuanzu.com 官方无关
