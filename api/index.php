<?php

namespace Verot\Upload;

require_once __DIR__ . '/../application/function.php';
require_once APP_ROOT . '/application/class.upload.php';
require_once APP_ROOT . '/config/api_key.php';

header('Access-Control-Allow-Origin:*');

// 无文件
if (empty($_FILES['image'])) {
    exit(json_encode(
        array(
            "result"    =>  "failed",
            "code"      =>  204,
            "message"   =>  "没有选择上传的文件",
        )
    ));
}

// 黑/白IP名单上传
if ($config['check_ip']) {
    if (checkIP(null, $config['check_ip_list'], $config['check_ip_model'])) {
        // 上传错误 code:205 未授权IP
        exit(json_encode(array(
            "result"    =>  "failed",
            "code"      =>  205,
            "message"   =>  "黑名单内或白名单外用户不允许上传",
        )));
    }
}

// 根据IP限制游客每日上传数量
if ($config['ip_upload_counts'] > 0 && !is_who_login('status')) {
    $ipList = APP_ROOT . '/admin/logs/ipcounts/' . date('Ymd') . '.php';
    if (is_file($ipList)) {
        $ipList = file_get_contents($ipList);
        $ipList = explode(PHP_EOL, $ipList);
        if (array_count_values($ipList)[real_ip()] >= $config['ip_upload_counts']) {
            exit(json_encode(
                array(
                    "result"    =>  "failed",
                    "code"      =>  403,
                    "message"   =>  "游客限制每日上传 " . $config['ip_upload_counts'] . ' 张',
                )
            ));
        }
        clearstatcache();
    }
}

$token = preg_replace('/[\W]/', '', $_POST['token']); // 获取Token并过滤非字母数字，删除空格;

// 检查api合法性
check_api($token);
$tokenID = $tokenList[$token]['id'];

$handle = new Upload($_FILES['image'], 'zh_CN');

if ($handle->uploaded) {
    // 允许上传的mime类型
    $handle->allowed = array('image/*');
    // 文件命名
    $handle->file_new_name_body = imgName($handle->file_src_name_body);
    // 添加Token ID
    $handle->file_name_body_add = '-' . $tokenID;
    // 最大上传限制
    $handle->file_max_size = $config['maxSize'];
    // 最大宽度
    $handle->image_max_width = $config['maxWidth'];
    // 最大高度
    $handle->image_max_height = $config['maxHeight'];
    // 最小宽度
    $handle->image_min_width = $config['minWidth'];
    // 最小高度
    $handle->image_min_height = $config['minHeight'];
    // 2023-01-06 转换图片为指定格式 只转换非webp格式和非动态图片
    if ($handle->file_src_name_ext !== 'webp' && !isGifAnimated($handle->file_src_pathname)) {
        $handle->image_convert = $config['imgConvert'];
    }
    // 2023-01-06 PNG 图像的压缩级别，介于 1（快速但大文件）和 9（慢但较小文件）之间
    $handle->png_compression = 9 - round($config['compress_ratio'] / 11.2);
    // WEBP 图像的压缩质量 1-100
    $handle->webp_quality = $config['compress_ratio'];
    // JPEG 图像的压缩质量 1-100
    $handle->jpeg_quality = $config['compress_ratio'];

    /* 等比例缩减图片 放到前端了*/
    /*
    if ($config['imgRatio']) {
        $handle->image_resize = true;
        $handle->image_x = $config['image_x'];
        $handle->image_y = $config['image_y'];
        // 如果调整后的图像大于原始图像，则取消调整大小，以防止放大
        $handle->image_no_enlarging = true;
    }
    */

    // 默认目录
    $Img_path = config_path();

    if ($config['token_path_status'] == 1) {
        $Img_path = config_path($tokenID . date('/Y/m/d/'));
    }

    // 存储图片路径:images/201807/
    $handle->process(APP_ROOT . $Img_path);

    // 图片完整相对路径:/i/2021/05/03/k88e7p.jpg
    if ($handle->processed) {
        header('Content-type:text/json');

        // 图片相对路径
        $pathIMG = $Img_path . $handle->file_dst_name;
        // 图片访问网址
        $imageUrl = rand_imgurl() . $pathIMG;
        // 后续处理地址
        $processUrl = $config['domain'] . $pathIMG;

        // 原图保护 key值是由crc32加密的hide_key
        // $hide_original = $config['hide'] == 1 ? $config['domain'] . '/application/hide.php?key=' . urlHash($pathIMG, 0, crc32($config['hide_key'])) : $imageUrl;

        /** 
         * 以下为控制开启源图保护或者返回值隐藏config文件中的path目录所更改 
         * 2022年5月1日
         */

        // 隐藏config文件中的path目录,需要搭配网站设置
        if ($config['hide_path'] == 1) {
            $imageUrl = str_replace($config['path'], '/', $imageUrl);
        }

        // 源图保护 key值是由crc32加密的hide_key
        if ($config['hide'] == 1) {
            $imageUrl = $config['domain'] . '/application/hide.php?key=' . urlHash($pathIMG, 0, crc32($config['hide_key']));
        }

        // 关闭上传后显示加密删除链接
        if ($config['show_user_hash_del']) {
            // 判断PHP版本启用删除
            if (PHP_VERSION >= '7') {
                $delUrl = $config['domain']  . '/application/del.php?hash=' . urlHash($pathIMG, 0);
            } else {
                $delUrl = "Sever PHP version lower 7.0";
            }
        } else {
            $delUrl = "Admin closed user delete";
        }

        // 当设置访问生成缩略图时自动生成 2022-12-30 修正 2023-01-30
        $handleThumb = $config['domain'] . '/application/thumb.php?img=' . $pathIMG;
        if ($config['thumbnail'] == 2) {
            // 自定义缩略图长宽
            $handle->image_resize = true;
            $handle->image_x = $config['thumbnail_w'];
            $handle->image_y = $config['thumbnail_h'];
            // 如果调整后的图像大于原始图像，则取消调整大小，以防止放大
            $handle->image_no_enlarging = true;
            $handle->file_new_name_body = date('Y_m_d_') . $handle->file_dst_name_body;
            $handle->process(APP_ROOT . $config['path'] . 'cache/');
            $handleThumb = $config['domain'] . $config['path'] . 'cache/' . $handle->file_dst_name;
        }

        // 上传成功后返回json数据
        $reJson = array(
            "result"    => "success",
            "code"      => 200,
            "url"       => $imageUrl,
            "srcName"   => $handle->file_src_name_body,
            "thumb"     => $handleThumb,
            "del"       => $delUrl,
        );
        echo json_encode($reJson, JSON_UNESCAPED_UNICODE);
        $handle->clean();
    } else {
        // 上传错误 code:206 客户端文件有问题
        $reJson = array(
            "result"    =>  "failed",
            "code"      =>  206,
            "message"   =>  $handle->error,
            // 'log' => $handle->log,(仅用作调试用)
        );
        unset($handle);
        header('Content-Type:application/json; charset=utf-8');
        exit(json_encode($reJson, JSON_UNESCAPED_UNICODE));
    }

    /** 后续处理 */
    // 使用fastcgi_finish_request操作
    if (function_exists('fastcgi_finish_request')) { // fastcgi_finish_request 模式
        fastcgi_finish_request();
        // 记录同IP上传次数
        @ip_upload_counts();
        // 上传日志
        @write_upload_logs($pathIMG, $handle->file_src_name, $handle->file_dst_pathname, $handle->file_src_size, $tokenID);
        // 鉴黄
        @process_checkImg($processUrl);
        // 水印        
        @water($handle->file_dst_pathname);
        // 压缩        
        @process_compress($handle->file_dst_pathname);
    } else { // 普通模式
        // 记录同IP上传次数
        @ip_upload_counts();
        // 上传日志
        @write_upload_logs($pathIMG, $handle->file_src_name, $handle->file_dst_pathname, $handle->file_src_size, $tokenID);
        // 鉴黄
        @process_checkImg($processUrl);
        // 水印
        @water($handle->file_dst_pathname);
        // 压缩
        @process_compress($handle->file_dst_pathname);
    }

    unset($handle);
}
