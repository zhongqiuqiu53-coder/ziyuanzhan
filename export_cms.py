#!/usr/bin/env python3
"""
导出 CMS (苹果CMS V8 / maccms) 可直接采集的接口格式 + 采集源清单。

输入（由 monitor.py 生成）:
    docs/data/online.json    —— 本地健康检测通过的站点
    docs/data/sources.json   —— ziyuanzu.com 提供的全量站点元数据

输出:
    docs/data/vod.json       —— maccms 标准采集接口（list/vod_* 字段）
    docs/data/apis.json      —— 采集源清单（含每个站的 api 字段）
    docs/data/apis.csv       —— 同上，UTF-8 BOM，Excel 可直接打开

用法:
    python export_cms.py
"""

import csv
import json
import os
from datetime import datetime

try:
    from pypinyin import lazy_pinyin
    HAS_PINYIN = True
except ImportError:
    HAS_PINYIN = False

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DATA_DIR = os.path.join(BASE_DIR, 'docs', 'data')

# ---------------------------------------------------------------- CMS 配置
# 采集条目要落到你 CMS 的哪个分类？改成你后台「分类管理」里对应的 ID。
# 填错的话条目能导入，但会进「未分类」。
CMS_TYPE_ID = 1
CMS_TYPE_NAME = '资源站'


def load_json(name):
    path = os.path.join(DATA_DIR, name)
    if not os.path.exists(path):
        return None
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def make_en(name, fallback):
    """生成 vod_en 用的拼音/净化短名，缺 pypinyin 时退化为去符号化。"""
    if HAS_PINYIN:
        parts = []
        for char in name:
            if '\u4e00' <= char <= '\u9fff':
                parts.extend(lazy_pinyin(char))
            else:
                parts.append(char)
        slug = ''.join(parts).lower()
        if slug:
            return slug
    cleaned = ''.join(c if c.isalnum() else ' ' for c in fallback)
    return '-'.join(cleaned.split()).lower() or 'item'


def host_of(url):
    text = (url or '').strip()
    for prefix in ('https://', 'http://'):
        if text.startswith(prefix):
            text = text[len(prefix):]
    return text.split('/')[0]


def collect():
    """合并健康检测结果与站点元数据。"""
    online = load_json('online.json')
    sources = load_json('sources.json')

    meta = {}
    if isinstance(sources, list):
        for item in sources:
            if isinstance(item, dict) and item.get('id') is not None:
                meta[item['id']] = item

    entries = []
    seen = set()
    if isinstance(online, dict):
        for item in online.get('data') or []:
            if not isinstance(item, dict):
                continue
            health = item.get('health') or {}
            if not health.get('is_alive'):
                continue
            entries.append({'site': item, 'health': health, 'meta': {}})
            seen.add(item.get('name'))

    # sources.json 里还剩的站点（含 ziyuanzu 标记 ok 但本地检测已失效的）
    if isinstance(sources, list):
        for item in sources:
            if not isinstance(item, dict) or item.get('name') in seen:
                continue
            entries.append({'site': item, 'health': {}, 'meta': item})

    for entry in entries:
        site = entry['site']
        m = entry['meta']
        site.setdefault('api', m.get('api'))
        site.setdefault('link', m.get('url'))
        site.setdefault('description', m.get('description'))
        site.setdefault('rating', m.get('rating'))
        site.setdefault('tags', m.get('tags'))
        site.setdefault('todayUpdates', m.get('todayUpdates'))
        site.setdefault('resource_count', m.get('totalResources'))
        site.setdefault('source_url', m.get('source_url'))

    # 排序：在线优先，其次按实测响应时间
    def sort_key(entry):
        health = entry['health']
        ms = health.get('response_time_ms')
        return (0 if health.get('is_alive') else 1, ms if ms is not None else 99999)

    entries.sort(key=sort_key)
    return entries


def build_vod(entries, timestamp):
    """maccms / 苹果CMS V8 标准采集接口格式。"""
    items = []
    for index, entry in enumerate(entries, start=1):
        site = entry['site']
        health = entry['health']
        link = site.get('link') or ''
        api = site.get('api') or ''

        ms = health.get('response_time_ms')
        code = health.get('status_code')
        rating = site.get('rating')

        remarks = []
        if health.get('is_alive'):
            code = health.get('status_code')
            if code and code >= 400:
                # 站点有响应但拒绝了我们（反爬/需要登录），能连上但不能直采
                remarks.append('在线·受限')
                remarks.append(str(code))
            else:
                remarks.append('在线')
                if code:
                    remarks.append(str(code))
            if ms is not None:
                remarks.append('%dms' % round(ms))
        else:
            remarks.append('离线')
        if rating:
            remarks.append('%s分' % rating)
        remark = ' · '.join(remarks)

        desc_bits = []
        if site.get('description'):
            desc_bits.append(site['description'])
        if api:
            desc_bits.append('采集接口: %s' % api)
        if site.get('resource_count'):
            desc_bits.append('资源数: %s' % site['resource_count'])
        if site.get('tags'):
            desc_bits.append('标签: %s' % '/'.join(site['tags']))

        play_url = ''
        if link:
            play_url = '访问$%s' % link

        items.append({
            'vod_id': index,
            'type_id': CMS_TYPE_ID,
            'type_name': CMS_TYPE_NAME,
            'vod_name': site.get('name') or '未命名站点',
            'vod_sub': host_of(link),
            'vod_en': make_en(site.get('name') or '', host_of(link)),
            'vod_time': timestamp,
            'vod_remarks': remark,
            'vod_play_from': '官方站',
            'vod_play_url': play_url,
            'vod_content': ' | '.join(desc_bits),
            'vod_pic': '',
            'vod_year': '',
            'vod_area': '',
            'vod_actor': '',
            'vod_director': '',
            'vod_class': CMS_TYPE_NAME,
        })

    total = len(items)
    return {
        'code': 1,
        'msg': '数据列表',
        'page': 1,
        'pagecount': 1,
        'limit': total,
        'total': total,
        'list': items,
        'class': [{
            'type_id': CMS_TYPE_ID,
            'type_name': CMS_TYPE_NAME,
            'type_pid': 0,
            'type_mid': 0,
            'type_status': 1,
        }],
    }


def build_apis(entries, timestamp):
    alive = [e for e in entries if e['health'].get('is_alive')]
    rows = []
    for entry in entries:
        site = entry['site']
        health = entry['health']
        rows.append({
            'name': site.get('name'),
            'api': site.get('api'),
            'link': site.get('link'),
            'source_url': site.get('source_url'),
            'status': '在线' if health.get('is_alive') else '离线',
            'status_code': health.get('status_code'),
            'response_ms': health.get('response_time_ms'),
            'rating': site.get('rating'),
            'speed': site.get('speed'),
            'resource_count': site.get('resource_count'),
            'today_updates': site.get('todayUpdates'),
            'tags': site.get('tags') or [],
            'description': site.get('description'),
        })
    return {
        'timestamp': timestamp,
        'total': len(rows),
        'online': len(alive),
        'offline': len(rows) - len(alive),
        'cms_type_id': CMS_TYPE_ID,
        'cms_type_name': CMS_TYPE_NAME,
        'data': rows,
    }


def write_csv(apis):
    path = os.path.join(DATA_DIR, 'apis.csv')
    headers = ['名称', 'API采集地址', '站点地址', '状态', 'HTTP状态', '响应ms',
               '评分', '资源数', '今日更新', '标签', '描述']
    with open(path, 'w', encoding='utf-8-sig', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(headers)
        for row in apis['data']:
            writer.writerow([
                row['name'] or '',
                row['api'] or '',
                row['link'] or '',
                row['status'],
                row['status_code'] if row['status_code'] is not None else '',
                round(row['response_ms']) if row['response_ms'] is not None else '',
                row['rating'] if row['rating'] is not None else '',
                row['resource_count'] if row['resource_count'] is not None else '',
                row['today_updates'] if row['today_updates'] is not None else '',
                '/'.join(row['tags']),
                row['description'] or '',
            ])


def main():
    entries = collect()
    if not entries:
        print('[WARN] 没有可用数据，请先运行 monitor.py')
        return 1

    online_doc = load_json('online.json') or {}
    timestamp = online_doc.get('timestamp') or datetime.now().strftime('%Y-%m-%d %H:%M:%S')

    vod = build_vod(entries, timestamp)
    apis = build_apis(entries, timestamp)

    with open(os.path.join(DATA_DIR, 'vod.json'), 'w', encoding='utf-8') as f:
        json.dump(vod, f, ensure_ascii=False, indent=2)
    with open(os.path.join(DATA_DIR, 'vod-online.json'), 'w', encoding='utf-8') as f:
        online_vod = dict(vod)
        online_items = [i for i, e in zip(vod['list'], entries) if e['health'].get('is_alive')]
        online_vod['list'] = online_items
        online_vod['limit'] = len(online_items)
        online_vod['total'] = len(online_items)
        json.dump(online_vod, f, ensure_ascii=False, indent=2)
    with open(os.path.join(DATA_DIR, 'apis.json'), 'w', encoding='utf-8') as f:
        json.dump(apis, f, ensure_ascii=False, indent=2)
    write_csv(apis)

    alive = apis['online']
    print('[INFO] CMS 采集接口: docs/data/vod.json  (%d 条)' % vod['total'])
    print('[INFO] 仅在线版:     docs/data/vod-online.json (%d 条)' % alive)
    print('[INFO] 采集源清单:   docs/data/apis.json (%d 条, 在线 %d)' % (apis['total'], alive))
    print('[INFO] 采集源表格:   docs/data/apis.csv')
    print('[DONE] 完成')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
