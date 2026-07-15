Таблица модулей:
Модуль	Файлы	Ответственность	Зависимости	Риски
Camera Manager	camera_manager*	Управление камерами	libuvc, JNI	Потоки, lifecycle
Decoder	decoder*	MJPEG-декодирование	TurboJPEG	Буферы, формат
Streaming	stream*	Передача кадров	HTTP/SRT	Задержки
Это позволит локальной модели выбирать только нужные файлы.