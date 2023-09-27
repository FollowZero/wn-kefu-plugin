# wn-kefu-plugin
客服-测试版

## 安装

由于包名的原因需要在项目根目录的 composer.json 文件中添加有自定义安装路径的代码
```
.
.
.
"extra": {
        "installer-paths": {
            "plugins/summer/{$name}/": ["vendor:summercms"]
        }
    }
.
.
.
```

```
composer require summercms/wn-kefu-plugin
```

```
php artisan winter:up
```
## 使用

## 其他

### 知识点



