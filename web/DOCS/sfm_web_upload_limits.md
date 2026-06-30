# SfM external web upload limits

External test videos can be several GB. The web form uses plain multipart upload and does not set an HTML `MAX_FILE_SIZE` limit. PHP still enforces server configuration limits before application code runs.

Recommended PHP settings for large test videos:

```ini
upload_max_filesize = 20G
post_max_size = 22G
max_input_time = 7200
max_execution_time = 7200
memory_limit = 512M
```

If Apache is used, check that no restrictive `LimitRequestBody` is configured for this virtual host/location.

If Nginx is used, set a request body limit large enough for the upload, for example:

```nginx
client_max_body_size 22G;
```

Also ensure there is enough free disk space and write permission in both the PHP temporary upload directory (`upload_tmp_dir`, or the system default if unset) and the final `/home/storage/orders/.../videos/` storage location.

The application-level safety limit is configurable with `SFM_WEB_UPLOAD_MAX_BYTES`; when it is not defined, the default is 20 GB. The upload handler moves files with `move_uploaded_file()` and does not load videos into PHP memory.