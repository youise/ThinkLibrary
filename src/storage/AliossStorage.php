<?php

// +----------------------------------------------------------------------
// | Library for ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2022 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: https://gitee.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | gitee 仓库地址 ：https://gitee.com/zoujingli/ThinkLibrary
// | github 仓库地址 ：https://github.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------

declare (strict_types=1);

namespace think\admin\storage;

use think\admin\Exception;
use think\admin\extend\HttpExtend;
use think\admin\Storage;

/**
 * 阿里云OSS存储支持
 * Class AliossStorage
 * @package think\admin\storage
 */
class AliossStorage extends Storage
{
    /**
     * 数据中心
     * @var string
     */
    private $point;

    /**
     * 存储空间名称
     * @var string
     */
    private $bucket;

    /**
     * AccessId
     * @var string
     */
    private $accessKey;

    /**
     * AccessSecret
     * @var string
     */
    private $secretKey;

    /**
     * 初始化入口
     * @throws \think\admin\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    protected function initialize()
    {
        // 读取配置文件
        $this->point = sysconf('storage.alioss_point');
        $this->bucket = sysconf('storage.alioss_bucket');
        $this->accessKey = sysconf('storage.alioss_access_key');
        $this->secretKey = sysconf('storage.alioss_secret_key');
        // 计算链接前缀
        $host = strtolower(sysconf('storage.alioss_http_domain'));
        $type = strtolower(sysconf('storage.alioss_http_protocol'));
        if ($type === 'auto') {
            $this->prefix = "//{$host}";
        } elseif (in_array($type, ['http', 'https'])) {
            $this->prefix = "{$type}://{$host}";
        } else {
            throw new Exception(lang('未配置阿里云URL域名哦'));
        }
    }

    /**
     * 上传文件内容
     * @param string $name 文件名称
     * @param string $file 文件内容
     * @param boolean $safe 安全模式
     * @param null|string $attname 下载名称
     * @return array
     */
    public function set(string $name, string $file, bool $safe = false, ?string $attname = null): array
    {
        $token = $this->buildUploadToken($name);
        $data = ['key' => $name];
        $data['policy'] = $token['policy'];
        $data['Signature'] = $token['signature'];
        $data['OSSAccessKeyId'] = $this->accessKey;
        $data['success_action_status'] = '200';
        if (is_string($attname) && strlen($attname) > 0) {
            $data['Content-Disposition'] = 'inline;filename=' . urlencode($attname);
        }
        $file = ['field' => 'file', 'name' => $name, 'content' => $file];
        if (is_numeric(stripos(HttpExtend::submit($this->upload(), $data, $file), '200 OK'))) {
            return ['file' => $this->path($name, $safe), 'url' => $this->url($name, $safe, $attname), 'key' => $name];
        } else {
            return [];
        }
    }

    /**
     * 根据文件名读取文件内容
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return string
     */
    public function get(string $name, bool $safe = false): string
    {
        return static::curlGet($this->url($name, $safe));
    }

    /**
     * 删除存储的文件
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return boolean
     */
    public function del(string $name, bool $safe = false): bool
    {
        [$file] = explode('?', $name);
        $result = HttpExtend::request('DELETE', "http://{$this->bucket}.{$this->point}/{$file}", [
            'returnHeader' => true, 'headers' => $this->headerSign('DELETE', $file),
        ]);
        return is_numeric(stripos($result, '204 No Content'));
    }

    /**
     * 判断文件是否存在
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return boolean
     */
    public function has(string $name, bool $safe = false): bool
    {
        $file = $this->delSuffix($name);
        $result = HttpExtend::request('HEAD', "http://{$this->bucket}.{$this->point}/{$file}", [
            'returnHeader' => true, 'headers' => $this->headerSign('HEAD', $file),
        ]);
        return is_numeric(stripos($result, 'HTTP/1.1 200 OK'));
    }

    /**
     * 获取文件当前URL地址
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @param null|string $attname 下载名称
     * @return string
     */
    public function url(string $name, bool $safe = false, ?string $attname = null): string
    {
        return "{$this->prefix}/{$this->delSuffix($name)}{$this->getSuffix($attname,$name)}";
    }

    /**
     * 获取文件存储路径
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @return string
     */
    public function path(string $name, bool $safe = false): string
    {
        return $this->url($name, $safe);
    }

    /**
     * 获取文件存储信息
     * @param string $name 文件名称
     * @param boolean $safe 安全模式
     * @param null|string $attname 下载名称
     * @return array
     */
    public function info(string $name, bool $safe = false, ?string $attname = null): array
    {
        return $this->has($name, $safe) ? [
            'url' => $this->url($name, $safe, $attname),
            'key' => $name, 'file' => $this->path($name, $safe),
        ] : [];
    }

    /**
     * 获取文件上传地址
     * @return string
     */
    public function upload(): string
    {
        $protocol = $this->app->request->isSsl() ? 'https' : 'http';
        return "{$protocol}://{$this->bucket}.{$this->point}";
    }

    /**
     * 获取文件上传令牌
     * @param string $name 文件名称
     * @param integer $expires 有效时间
     * @param null|string $attname 下载名称
     * @return array
     */
    public function buildUploadToken(string $name, int $expires = 3600, ?string $attname = null): array
    {
        $data = [
            'policy'  => base64_encode(json_encode([
                'conditions' => [['content-length-range', 0, 1048576000]],
                'expiration' => date('Y-m-d\TH:i:s.000\Z', time() + $expires),
            ])),
            'keyid'   => $this->accessKey,
            'siteurl' => $this->url($name, false, $attname),
        ];
        $data['signature'] = base64_encode(hash_hmac('sha1', $data['policy'], $this->secretKey, true));
        return $data;
    }

    /**
     * 操作请求头信息签名
     * @param string $method 请求方式
     * @param string $soruce 资源名称
     * @param array $header 请求头信息
     * @return array
     */
    private function headerSign(string $method, string $soruce, array $header = []): array
    {
        if (empty($header['Date'])) $header['Date'] = gmdate('D, d M Y H:i:s \G\M\T');
        if (empty($header['Content-Type'])) $header['Content-Type'] = 'application/xml';
        uksort($header, 'strnatcasecmp');
        $content = "{$method}\n\n";
        foreach ($header as $key => $value) {
            $value = str_replace(["\r", "\n"], '', $value);
            if (in_array(strtolower($key), ['content-md5', 'content-type', 'date'])) {
                $content .= "{$value}\n";
            } elseif (stripos($key, 'x-oss-') === 0) {
                $content .= strtolower($key) . ":{$value}\n";
            }
        }
        $content = rawurldecode($content) . "/{$this->bucket}/{$soruce}";
        $signature = base64_encode(hash_hmac('sha1', $content, $this->secretKey, true));
        $header['Authorization'] = "OSS {$this->accessKey}:{$signature}";
        foreach ($header as $key => $value) $header[$key] = "{$key}: {$value}";
        return array_values($header);
    }

    /**
     * 阿里云OSS存储区域
     * @return array
     */
    public static function region(): array
    {
        return [
            'oss-cn-hangzhou.aliyuncs.com'    => lang('华东 1（杭州）'),
            'oss-cn-shanghai.aliyuncs.com'    => lang('华东 2（上海）'),
            'oss-cn-nanjing.aliyuncs.com'     => lang('华东 5（南京本地地域）'),
            'oss-cn-fuzhou.aliyuncs.com'      => lang('华东 6（福州本地地域）'),
            'oss-cn-qingdao.aliyuncs.com'     => lang('华北 1（青岛）'),
            'oss-cn-beijing.aliyuncs.com'     => lang('华北 2（北京）'),
            'oss-cn-zhangjiakou.aliyuncs.com' => lang('华北 3（张家口）'),
            'oss-cn-huhehaote.aliyuncs.com'   => lang('华北 5（呼和浩特）'),
            'oss-cn-wulanchabu.aliyuncs.com'  => lang('华北 6（乌兰察布）'),
            'oss-cn-shenzhen.aliyuncs.com'    => lang('华南 1（深圳）'),
            'oss-cn-heyuan.aliyuncs.com'      => lang('华南 2（河源）'),
            'oss-cn-guangzhou.aliyuncs.com'   => lang('华南 3（广州）'),
            'oss-cn-chengdu.aliyuncs.com'     => lang('西南 1（成都）'),
            'oss-cn-hongkong.aliyuncs.com'    => lang('中国（香港）'),
            'oss-us-west-1.aliyuncs.com'      => lang('美国（硅谷）'),
            'oss-us-east-1.aliyuncs.com'      => lang('美国（弗吉尼亚）'),
            'oss-ap-northeast-1.aliyuncs.com' => lang('日本（东京）'),
            'oss-ap-northeast-2.aliyuncs.com' => lang('韩国（首尔）'),
            'oss-ap-southeast-1.aliyuncs.com' => lang('新加坡'),
            'oss-ap-southeast-2.aliyuncs.com' => lang('澳大利亚（悉尼）'),
            'oss-ap-southeast-3.aliyuncs.com' => lang('马来西亚（吉隆坡）'),
            'oss-ap-southeast-5.aliyuncs.com' => lang('印度尼西亚（雅加达）'),
            'oss-ap-southeast-6.aliyuncs.com' => lang('菲律宾（马尼拉）'),
            'oss-ap-southeast-7.aliyuncs.com' => lang('泰国（曼谷）'),
            'oss-ap-south-1.aliyuncs.com'     => lang('印度（孟买）'),
            'oss-eu-central-1.aliyuncs.com'   => lang('德国（法兰克福）'),
            'oss-eu-west-1.aliyuncs.com'      => lang('英国（伦敦）'),
            'oss-me-east-1.aliyuncs.com'      => lang('阿联酋（迪拜）'),
        ];
    }
}