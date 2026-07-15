Модель должна точно знать:

    как собрать проект;

    как запустить;

    как воспроизвести ошибку;

    какие тесты обязательны;

    какие команды безопасны;

    какие результаты считаются успешными.

Пример:

Build:
cmake -S . -B build
cmake --build build -j8

Tests:
ctest --test-dir build --output-on-failure

Success criteria:
- build exits with code 0
- all tests pass
- no new compiler warnings
- expected runtime log contains "selected format=MJPEG"