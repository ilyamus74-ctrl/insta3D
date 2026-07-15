Один из самых важных документов.

Контракты описывают:

публичные функции;
API;
структуры данных;
форматы файлов;
события;
очереди;
callback-интерфейсы;
JNI-границы;
HTTP-запросы и ответы;
допустимые ошибки;
владение памятью;
потокобезопасность.

Пример:

Contract: CameraFrame

Producer:
- UVC capture thread

Consumer:
- Decoder thread

Fields:
- data: pointer to compressed frame
- size: exact number of valid bytes
- timestamp_us: monotonic timestamp
- format: MJPEG or YUYV

Ownership:
- Producer owns the buffer until callback returns.
- Consumer must copy data before returning if asynchronous processing is required.

Failure conditions:
- size == 0
- data == nullptr
- unsupported format
- incomplete MJPEG frame