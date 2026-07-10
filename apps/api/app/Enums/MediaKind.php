<?php

namespace App\Enums;

enum MediaKind: string
{
    case Video = 'video';
    case Audio = 'audio';
    case Keyframe = 'keyframe';
    case Thumbnail = 'thumbnail';
    case ScreenRecording = 'screen_recording';
}
