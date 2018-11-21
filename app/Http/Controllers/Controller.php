<?php

namespace App\Http\Controllers;

use App\Http\Models\SensitiveWords;
use App\Http\Models\UserSubscribe;
use App\Http\Models\UserTrafficModifyLog;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    // 生成随机密码
    public function makePasswd()
    {
        exit(makeRandStr());
    }

    // 生成VmessId
    public function makeVmessId()
    {
        exit(createGuid());
    }

    // 生成订阅地址的唯一码
    public function makeSubscribeCode()
    {
        $code = makeRandStr(5);
        if (UserSubscribe::query()->where('code', $code)->exists()) {
            $code = $this->makeSubscribeCode();
        }

        return $code;
    }

    // 类似Linux中的tail命令
    public function tail($file, $n, $base = 5)
    {
        $fileLines = $this->countLine($file);
        if ($fileLines < 15000) {
            return false;
        }

        $fp = fopen($file, "r+");
        assert($n > 0);
        $pos = $n + 1;
        $lines = [];
        while (count($lines) <= $n) {
            try {
                fseek($fp, -$pos, SEEK_END);
            } catch (\Exception $e) {
                fseek(0);
                break;
            }

            $pos *= $base;
            while (!feof($fp)) {
                array_unshift($lines, fgets($fp));
            }
        }

        return array_slice($lines, 0, $n);
    }

    /**
     * 计算文件行数
     */
    public function countLine($file)
    {
        $fp = fopen($file, "r");
        $i = 0;
        while (!feof($fp)) {
            //每次读取2M
            if ($data = fread($fp, 1024 * 1024 * 2)) {
                //计算读取到的行数
                $num = substr_count($data, "\n");
                $i += $num;
            }
        }

        fclose($fp);

        return $i;
    }

    // 获取敏感词
    public function sensitiveWords()
    {
        return SensitiveWords::query()->get()->pluck('words')->toArray();
    }

    // 将Base64图片转换为本地图片并保存
    function base64ImageSaver($base64_image_content)
    {
        // 匹配出图片的格式
        if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64_image_content, $result)) {
            $type = $result[2];

            $directory = date('Ymd');
            $path = '/assets/images/qrcode/' . $directory . '/';
            if (!file_exists(public_path($path))) { // 检查是否有该文件夹，如果没有就创建，并给予最高权限
                mkdir(public_path($path), 0755, true);
            }

            $fileName = makeRandStr(18, true) . ".{$type}";
            if (file_put_contents(public_path($path . $fileName), base64_decode(str_replace($result[1], '', $base64_image_content)))) {
                chmod(public_path($path . $fileName), 0744);

                return $path . $fileName;
            } else {
                return '';
            }
        } else {
            return '';
        }
    }
}
