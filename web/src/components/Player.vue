<template>
    <div id="artplayer" ref="$container" class="w-full md:flex-1 artplayer-app"></div>
</template>
<script lang="ts" setup>
import { onMounted, ref, shallowRef, onBeforeUnmount } from 'vue';
import Artplayer from 'artplayer'
import artplayerPluginDanmuku, { type Option } from 'artplayer-plugin-danmuku';
import { type Option as DanmakuOption } from  'artplayer-plugin-danmuku';

const DANMAKU_CONFIG_KEY = 'mybili_danmaku_config'
// 插件速度档位从 10/7.5/5/2.5/1 改成 20/15/10/5/2（每档慢一倍），旧存档要折算
const DANMAKU_CONFIG_VER = 2
const OLD_SPEED_MAP: Record<string, number> = { '10': 20, '7.5': 15, '5': 10, '2.5': 5, '1': 2 }

// 弹幕密度：每秒最多显示几条，0 = 不限
const DENSITY_KEY = 'mybili_density_limit'
const DENSITY_STEPS = [0, 20, 12, 8, 5, 3, 2, 1]
const DENSITY_DEFAULT = 3

const art = shallowRef<Artplayer | null>(null)
const $container = ref<HTMLDivElement | null>(null)
const densityLimit = ref(DENSITY_DEFAULT)
// 未过滤的全量弹幕，改密度时在它上面重新抽样，不用重新请求接口
let fullDanmaku: any[] = []

// 定义事件
const emit = defineEmits<{
    ready: []
}>()

const props = defineProps<{
    danmaku: any[]
    url: string
}>()

/**
 * 从 localStorage 读取弹幕配置
 */
const loadDanmakuConfig = (): Partial<Option> | null => {
    try {
        const saved = localStorage.getItem(DANMAKU_CONFIG_KEY)
        if (saved) {
            const config = JSON.parse(saved)
            if (config.ver !== DANMAKU_CONFIG_VER) {
                // 旧档位的 speed 按新表折算，折算不到的删掉走默认值（留着 undefined 会盖掉默认值）
                const mapped = OLD_SPEED_MAP[String(config.speed)]
                if (mapped) config.speed = mapped
                else delete config.speed
                config.ver = DANMAKU_CONFIG_VER
                localStorage.setItem(DANMAKU_CONFIG_KEY, JSON.stringify(config))
            }
            return config
        }
    } catch (error) {
        console.error('读取弹幕配置失败:', error)
    }
    return null
}

/**
 * 保存弹幕配置到 localStorage
 * 只保存预设中的 key
 */
const saveDanmakuConfig = (config: DanmakuOption) => {
    try {
        const configToSave: Partial<Option> = {
            speed: config.speed,
            antiOverlap: config.antiOverlap,
            synchronousPlayback: config.synchronousPlayback,
            fontSize: config.fontSize,
            theme: config.theme,
            margin: config.margin,
            modes: config.modes,
        } as Partial<Option>
        ;(configToSave as any).ver = DANMAKU_CONFIG_VER
        localStorage.setItem(DANMAKU_CONFIG_KEY, JSON.stringify(configToSave))
        console.log('弹幕配置已保存:', configToSave)
    } catch (error) {
        console.error('保存弹幕配置失败:', error)
    }
}

const loadDensityLimit = () => {
    const raw = localStorage.getItem(DENSITY_KEY)
    // 注意 Number(null) 是 0，会和「不限」档撞上，没存过必须按默认值走
    const saved = raw === null ? NaN : Number(raw)
    densityLimit.value = DENSITY_STEPS.includes(saved) ? saved : DENSITY_DEFAULT
}

const saveDensityLimit = () => {
    try {
        localStorage.setItem(DENSITY_KEY, String(densityLimit.value))
    } catch (error) {
        console.error('保存弹幕密度失败:', error)
    }
}

const densityLabel = () => (densityLimit.value ? `${densityLimit.value} 条/秒` : '不限')

/**
 * 按秒分桶、每桶等间隔抽样，超出的直接丢弃（延后会让弹幕落到错误的时间点）
 * 入参必须按 time 升序，见 sortByTime
 */
const sortByTime = (list: any[]) => list.slice().sort((a, b) => a.time - b.time)

const densityFilter = (list: any[]): any[] => {
    const limit = densityLimit.value
    if (!limit || !list.length) return list
    const out: any[] = []
    let i = 0
    while (i < list.length) {
        const sec = Math.floor(list[i].time)
        let j = i + 1
        while (j < list.length && Math.floor(list[j].time) === sec) j++
        const count = j - i
        if (count <= limit) {
            for (let k = i; k < j; k++) out.push(list[k])
        } else {
            for (let m = 0; m < limit; m++) out.push(list[i + Math.floor(m * count / limit)])
        }
        i = j
    }
    return out
}

const applyDanmaku = (list: any[]) => {
    const plugin = (art.value?.plugins as any)?.artplayerPluginDanmuku
    if (!plugin) return
    // 插件装载队列只认 danmuku 这个拼写，写成 danmaku 不会生效
    plugin.config({ danmuku: densityFilter(list) })
    plugin.load()
}

const switchVideo = (param: { url: string, danmaku: any[] }) => {
    if (art.value) {
        // /api/danmaku 没有 ORDER BY，返回顺序和时间无关（实测 27 万条里 11 万处逆序），
        // 不先排序，按秒分桶就退化成一秒一个桶，抽样等于没做
        fullDanmaku = sortByTime(param.danmaku || [])
        applyDanmaku(fullDanmaku)
        art.value.url = param.url
        art.value.play()
    }
}

onMounted(async () => {
    const isMobile = document.documentElement.clientWidth < 768
    const volume = isMobile ? 1 : 0.5
    const fullscreenWeb = isMobile ? false : true

    loadDensityLimit()
    fullDanmaku = sortByTime(props.danmaku || [])

    // 默认配置（speed 单位是「一条弹幕横穿屏幕的秒数」，越大越慢；档位只有 20/15/10/5/2）
    const defaultDanmakuOption = {
        speed: 10,
        antiOverlap: true,
        synchronousPlayback: false,
        fontSize: isMobile ? 14 : 25,
        theme: "light",
        margin: isMobile ? [10, '75%'] as [number | `${number}%`, number | `${number}%`] : [10, 10] as [number | `${number}%`, number | `${number}%`],
        modes: [0, 1, 2],
    } as Option

    // 读取已保存的配置
    const savedConfig = loadDanmakuConfig()
    
    // 合并配置：优先使用已保存的配置，其次使用默认配置
    const presetDanmakuOption = {
        ...defaultDanmakuOption,
        ...savedConfig,
        danmuku: densityFilter(fullDanmaku),
    } as Option

    console.log('使用的弹幕配置:', presetDanmakuOption)

    const plugins: any[] = [
        artplayerPluginDanmuku(presetDanmakuOption),
    ]
    art.value = new Artplayer({
        container: $container.value as HTMLDivElement,
        fullscreen: true,
        fullscreenWeb: fullscreenWeb,
        autoOrientation: true,
        url: props.url,
        setting: true,
        volume: volume,
        flip: true,
        playbackRate: true,
        theme: "#e749a0",
        miniProgressBar: true,

        plugins: plugins
    })
    // 监听弹幕配置变化并保存
    art.value?.on('artplayerPluginDanmuku:config', (...args: unknown[]) => {
        const option = args[0] as DanmakuOption
        console.info('弹幕配置变化:', option);
        saveDanmakuConfig(option)
    })

    art.value.setting.add({
        html: '弹幕密度',
        tooltip: densityLabel(),
        selector: DENSITY_STEPS.map(v => ({
            html: v ? `${v} 条/秒` : '不限',
            value: v,
            default: v === densityLimit.value,
        })),
        onSelect: (item: any) => {
            densityLimit.value = Number(item.value)
            saveDensityLimit()
            // 重新抽样装载会重置队列，当前屏上的弹幕消失重来（和切换分P同一机制）
            applyDanmaku(fullDanmaku)
            return densityLabel()
        },
    })

    emit('ready')
})
onBeforeUnmount(() => {
    art.value?.destroy(false)
})
defineExpose({
    switchVideo,
})
</script>
<style scoped>
.artplayer-app {
    width: 100%;
    height: 600px;
    position: relative;
    overflow: hidden;
}

@media (max-width: 768px) {
    .artplayer-app {
        height: 300px;
    }
}
</style>
