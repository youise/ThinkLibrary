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

namespace think\admin\support\middleware;

use Closure;
use think\admin\Exception;
use think\admin\extend\CodeExtend;
use think\admin\extend\JwtExtend;
use think\App;
use think\exception\HttpResponseException;
use think\Request;
use think\Response;
use think\Session;

/**
 * 兼容会话中间键
 * @class JwtInit
 * @package think\admin\support\middleware
 */
class JwtInit
{
    /**
     * 当前 App 对象
     * @var \think\App
     */
    protected $app;

    /**
     * 当前 Session 对象
     * @var \think\Session
     */
    protected $session;

    /**
     * Construct
     * @param \think\App $app
     * @param \think\Session $session
     */
    public function __construct(App $app, Session $session)
    {
        $this->app = $app;
        $this->session = $session;
    }

    /**
     * Jwt Session 初始化
     * @param \think\Request $request
     * @param \Closure $next
     * @return \think\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 处理 Jwt 请求，请求头存在 jwt-token 字段
        if (($token = $request->header('jwt-token', ''))) try {
            if (preg_match('#^\s*([\w\-]+\.[\w\-]+\.[\w\-]+)\s*$#', $token, $match)) {
                if (($data = JwtExtend::verifyToken($match[1])) && !empty($data['sub'])) {
                    $sessionId = CodeExtend::decrypt($data['sub'], JwtExtend::jwtkey());
                }
            } else {
                throw new Exception('访问 Jwt Token 格式错误！');
            }
        } catch (\Exception $exception) {
            throw new HttpResponseException(json([
                'code' => 0, 'info' => lang($exception->getMessage()),
            ]));
        }

        $cookieName = $this->session->getName();

        if (empty($sessionId)) {
            $varSessionId = $this->app->config->get('session.var_session_id');
            if ($varSessionId && $request->request($varSessionId)) {
                $sessionId = $request->request($varSessionId);
            } else {
                $sessionId = $request->cookie($cookieName);
            }
        }

        if ($sessionId) {
            $this->session->setId($sessionId);
        }

        // Session 初始化
        $this->session->init();
        $request->withSession($this->session);

        /** @var Response $response */
        $response = $next($request);
        $response->setSession($this->session);

        // 自动升级为Jwt会话
        if (!(JwtExtend::$isJwt && JwtExtend::setJwtSession())) {
            // 非 Jwt 请求禁止使用Jwt会话
            if (JwtExtend::isJwtSession()) throw new HttpResponseException(json([
                'code' => 0, 'info' => lang('请使用 JWT 方式访问！'),
            ]));
            // 非 Jwt 请求需要写入 Cookie 记录 SessionID
            $this->app->cookie->set($cookieName, $this->session->getId());
        }

        return $response;
    }

    /**
     * 保存会话数据
     * @return void
     */
    public function end()
    {
        // 自动检查并升级Jwt会话
        JwtExtend::$isJwt && JwtExtend::setJwtSession();
        // 保存当前的会话数据
        $this->session->save();
    }
}