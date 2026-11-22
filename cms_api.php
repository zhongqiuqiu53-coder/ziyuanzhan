<?php
/**
 * ziyuanzhan 资源站监测 → 苹果CMS V10 / 海洋CMS 采集接口桥接器
 * ------------------------------------------------------------------
 * 本脚本把 ziyuanzhan 监测到的资源站数据，包装成苹果CMS 标准采集接口，
 * 支持 CMS 采集器会发出的全部请求形态：
 *
 *   /api.php/provide/vod/            JSON 列表（?ac=list）
 *   /api.php/provide/vod/?ac=detail&ids=1,2   JSON 详情
 *   /api.php/provide/vod/at/json/    JSON 列表（带筛选形态）
 *   /api.php/provide/vod/at/xml/     XML 列表
 *   /api.php/provide/vod/?at=xml     XML 列表（query 形态）
 *
 * 支持的采集参数（苹果CMS 标准）：
 *   ac=list|detail   动作
 *   t                分类 ID
 *   ids              数据 ID，多个用逗号分隔
 *   pg               页码，从 1 开始
 *   wd               搜索关键词
 *   limit            每页条数
 *
 * 安装：
 *   1) 上传本文件到你的 PHP 主机，例如 /zy/api.php
 *   2) CMS 后台 → 采集 → 自定义资源库 / 接口管理
 *        资源库地址： https://你的域名/zy/api.php/provide/vod/
 *        接口类型：  JSON（要 XML 就改成 .../provide/vod/at/xml/）
 *   3) 点「测试接口」，应返回 code=1 且 list 非空
 *
 * 要求：PHP 7.0+，且 allow_url_fopen 或 cURL 至少开启一个（用于拉取数据源）。
 * 数据源默认指向本仓库的 GitHub Pages 部署；若换仓库，改 SOURCE_URL 即可。
 * 结果会缓存 CACHE_TTL 秒，避免每次采集都打外网。
 */

const SOURCE_URL = 'https://zhongqiuqiu53-coder.github.io/ziyuanzhan/data/vod.json';
const CACHE_TTL = 600;
const CACHE_FILE = __DIR__ . '/.zyz_vod_cache.json';

/**
 * 拉取数据源（带本地缓存 + 过期缓存兜底）
 * @return array|null
 */
function zyz_fetch_source()
{
    // 1) 命中新鲜缓存
    if (is_file(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
        $data = zyz_decode((string) @file_get_contents(CACHE_FILE));
        if ($data !== null) {
            return $data;
        }
    }

    // 2) 拉远端
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init(SOURCE_URL);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'ziyuanzhan-cms-bridge/1.0',
        ));
        $body = curl_exec($ch);
        curl_close($ch);
        if ($body !== false && $body !== null) {
            $raw = $body;
        }
    }
    if ($raw === null && ini_get('allow_url_fopen')) {
        $body = @file_get_contents(SOURCE_URL);
        if ($body !== false) {
            $raw = $body;
        }
    }

    // 3) 拉成功就更新缓存
    if ($raw !== null) {
        $data = zyz_decode($raw);
        if ($data !== null) {
            @file_put_contents(CACHE_FILE, $raw);
            return $data;
        }
    }

    // 4) 兜底：过期缓存也比 500 强
    if (is_file(CACHE_FILE)) {
        $data = zyz_decode((string) @file_get_contents(CACHE_FILE));
        if ($data !== null) {
            return $data;
        }
    }

    return null;
}

function zyz_decode($raw)
{
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || !isset($data['list']) || !is_array($data['list'])) {
        return null;
    }
    return $data;
}

/**
 * 取 PATH_INFO，兼容 PATH_INFO 被禁用的服务器
 */
function zyz_path_info()
{
    if (!empty($_SERVER['PATH_INFO'])) {
        return $_SERVER['PATH_INFO'];
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $path = parse_url($uri, PHP_URL_PATH);
    if ($path === false || $path === null) {
        return '';
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    if ($script !== '' && strpos($path, $script) === 0) {
        $path = substr($path, strlen($script));
    }
    return $path;
}

/**
 * 输出格式判定：路径里的 /at/xml/ 或 query 里的 at=xml 都走 XML
 */
function zyz_want_xml()
{
    $path = zyz_path_info();
    if (stripos($path, '/at/xml') !== false) {
        return true;
    }
    if (stripos($path, '/at/xmlsea') !== false) {
        return true;
    }
    if (isset($_GET['at']) && strtolower($_GET['at']) === 'xml') {
        return true;
    }
    return false;
}

function zyz_cdata($text)
{
    return str_replace(']]>', ']]]]><![CDATA[>', (string) $text);
}

/**
 * JSON 结构 → 苹果CMS XML 结构
 */
function zyz_to_xml($data)
{
    $out  = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
    $out .= "<rss version=\"5.0\">\n";
    $out .= sprintf(
        "<list page=\"%s\" pagecount=\"%s\" pagesize=\"%s\" recordcount=\"%s\">\n",
        $data['page'],
        $data['pagecount'],
        $data['limit'],
        $data['total']
    );

    foreach ($data['list'] as $v) {
        $play = isset($v['vod_play_url']) ? $v['vod_play_url'] : '';
        if ($play !== '' && substr($play, -1) !== '|') {
            $play .= '|';
        }
        $out .= "<video>\n";
        $out .= '<last>' . (isset($v['vod_time']) ? $v['vod_time'] : '') . "</last>\n";
        $out .= '<id>' . (isset($v['vod_id']) ? $v['vod_id'] : '') . "</id>\n";
        $out .= '<tid>' . (isset($v['type_id']) ? $v['type_id'] : '') . "</tid>\n";
        $out .= '<name><![CDATA[' . zyz_cdata(isset($v['vod_name']) ? $v['vod_name'] : '') . ']]></name>' . "\n";
        $out .= '<type>' . (isset($v['type_name']) ? $v['type_name'] : '') . "</type>\n";
        $out .= '<dt>' . (isset($v['vod_play_from']) ? $v['vod_play_from'] : '') . "</dt>\n";
        $out .= '<note><![CDATA[' . zyz_cdata(isset($v['vod_remarks']) ? $v['vod_remarks'] : '') . ']]></note>' . "\n";
        $out .= '<dl><dd from="' . (isset($v['vod_play_from']) ? $v['vod_play_from'] : '') . '"><![CDATA['
              . zyz_cdata($play) . ']]></dd></dl>' . "\n";
        $out .= '<des><![CDATA[' . zyz_cdata(isset($v['vod_content']) ? $v['vod_content'] : '') . ']]></des>' . "\n";
        $out .= '<vlink><![CDATA[' . zyz_cdata(isset($v['vod_sub']) ? $v['vod_sub'] : '') . ']]></vlink>' . "\n";
        $out .= '<plink><![CDATA[' . zyz_cdata(isset($v['vod_play_url']) ? $v['vod_play_url'] : '') . ']]></plink>' . "\n";
        $out .= "</video>\n";
    }

    $out .= "</list>\n<class>";
    if (!empty($data['class']) && is_array($data['class'])) {
        foreach ($data['class'] as $c) {
            $out .= sprintf(
                '<ty id="%s">%s</ty>',
                isset($c['type_id']) ? $c['type_id'] : '',
                isset($c['type_name']) ? $c['type_name'] : ''
            );
        }
    }
    $out .= "</class>\n</rss>";

    return $out;
}

/**
 * 主流程
 */
$data = zyz_fetch_source();
if ($data === null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'code' => 0,
        'msg'  => '数据源拉取失败，请检查服务器是否能访问 ' . SOURCE_URL,
        'list' => array(),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

$ac = isset($_GET['ac']) ? strtolower(trim($_GET['ac'])) : 'list';
$list = $data['list'];

if ($ac === 'detail') {
    // 详情：按 ids 过滤
    $ids = isset($_GET['ids']) ? $_GET['ids'] : '';
    if ($ids !== '') {
        $want = array_map('trim', explode(',', $ids));
        $list = array_values(array_filter($list, function ($v) use ($want) {
            $id = isset($v['vod_id']) ? (string) $v['vod_id'] : '';
            return in_array($id, $want, true);
        }));
    }
    $data['list'] = $list;
    $data['total'] = count($list);
    $data['limit'] = count($list);
    $data['page'] = 1;
    $data['pagecount'] = 1;
} else {
    // 列表：支持 t / wd / pg / limit
    $t = isset($_GET['t']) ? trim((string) $_GET['t']) : '';
    if ($t !== '' && $t !== '0') {
        $list = array_values(array_filter($list, function ($v) use ($t) {
            $tid = isset($v['type_id']) ? (string) $v['type_id'] : '';
            return $tid === $t;
        }));
    }

    $wd = isset($_GET['wd']) ? trim((string) $_GET['wd']) : '';
    if ($wd !== '') {
        $list = array_values(array_filter($list, function ($v) use ($wd) {
            $name = isset($v['vod_name']) ? $v['vod_name'] : '';
            return stripos($name, $wd) !== false;
        }));
    }

    $total = count($list);
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : max($total, 1);
    if ($limit < 1) {
        $limit = max($total, 1);
    }
    $page = isset($_GET['pg']) ? (int) $_GET['pg'] : 1;
    if ($page < 1) {
        $page = 1;
    }
    $pagecount = max(1, (int) ceil($total / $limit));

    $data['list'] = array_slice($list, ($page - 1) * $limit, $limit);
    $data['total'] = $total;
    $data['limit'] = $limit;
    $data['page'] = $page;
    $data['pagecount'] = $pagecount;
}

$data['msg'] = ($ac === 'detail') ? '数据详情' : '数据列表';

if (zyz_want_xml()) {
    header('Content-Type: text/xml; charset=utf-8');
    echo zyz_to_xml($data);
} else {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
