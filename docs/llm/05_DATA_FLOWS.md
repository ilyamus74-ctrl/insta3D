USB camera → libuvc callback → compressed frame buffer → MJPEG decoder → RGB frame → inference → tracker → overlay → stream/recording

Для каждого этапа:

поток;
формат;
размер данных;
владелец памяти;
синхронизация;
возможная потеря данных;
логирование.