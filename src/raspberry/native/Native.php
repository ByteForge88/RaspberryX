<?php

declare(strict_types=1);

namespace raspberry\native;

use raspberry\Server;

use FFI;
use FFI\CData;
use Closure;
use Throwable;

use function count;
use function extension_loaded;
use function is_file;

use const PHP_OS_FAMILY;

class Native{

    /** the library has to speak this version of the functions below */
    public const int ABI_VERSION = 1;

    private const string HEADER = <<<'C'
        uint32_t rx_version(void);
        void rx_free(char* data, size_t length);
        int32_t rx_poll(uint64_t* id, char** out, size_t* out_length);

        uint64_t rx_chunk_new(uint32_t air, uint32_t biome);
        void rx_chunk_free(uint64_t chunk);
        uint32_t rx_chunk_get_block(uint64_t chunk, uint32_t x, int32_t y, uint32_t z, uint32_t layer);
        int32_t rx_chunk_set_block(uint64_t chunk, uint32_t x, int32_t y, uint32_t z, uint32_t layer, uint32_t state);
        uint32_t rx_chunk_get_biome(uint64_t chunk, uint32_t x, int32_t y, uint32_t z);
        int32_t rx_chunk_set_biome(uint64_t chunk, uint32_t x, int32_t y, uint32_t z, uint32_t biome);
        void rx_chunk_fill(uint64_t chunk, int32_t min_y, int32_t max_y, uint32_t layer, uint32_t state);
        void rx_chunk_fill_biome(uint64_t chunk, uint32_t biome);
        int32_t rx_chunk_highest_subchunk(uint64_t chunk);
        void rx_chunk_collect_garbage(uint64_t chunk);
        int32_t rx_chunk_find(uint64_t chunk, const uint32_t* states, size_t count, char** out, size_t* out_length);
        
        uint64_t rx_light_new(void);
        void rx_light_free(uint64_t world);
        void rx_light_blocks(const uint32_t* states, const uint8_t* values, size_t count);
        int32_t rx_light_add_chunk(uint64_t world, int32_t chunk_x, int32_t chunk_z, uint64_t chunk);
        void rx_light_remove_chunk(uint64_t world, int32_t chunk_x, int32_t chunk_z);
        void rx_light_update(uint64_t world, int32_t x, int32_t y, int32_t z);
        int32_t rx_light_get(uint64_t world, int32_t x, int32_t y, int32_t z);
        int32_t rx_light_sky_bottom(uint64_t world, int32_t x, int32_t z);
        
        int32_t rx_chunk_export(uint64_t chunk, char** out, size_t* out_length);
        int32_t rx_chunk_import(uint64_t chunk, const char* data, size_t length);
        uint64_t rx_chunk_encode(uint64_t chunk, int32_t chunk_x, int32_t chunk_z, uint64_t revision, const char* extra, size_t extra_length, int32_t threshold, int32_t dimension);
        int32_t rx_chunk_copy(uint64_t from, uint64_t to);

        void rx_generator_states(char** out, size_t* out_length);
        uint64_t rx_old_generate(int64_t seed, const uint32_t* states, size_t count);
        void rx_old_border(uint64_t chunk, int32_t chunk_x, int32_t chunk_z, uint32_t barrier);
        uint64_t rx_pre_normal_new(int64_t seed, const uint32_t* states, size_t count);
        void rx_pre_normal_free(uint64_t generator);
        uint64_t rx_pre_normal_chunk(uint64_t generator, int32_t chunk_x, int32_t chunk_z);
        uint64_t rx_pre_normal_spawn(uint64_t generator);
        
        uint64_t rx_mobs_new(int64_t seed);
        void rx_mobs_free(uint64_t world);
        void rx_mobs_chunk(uint64_t world, int32_t chunk_x, int32_t chunk_z, uint64_t chunk);
        void rx_mobs_blocks(const uint32_t* states, const uint16_t* flags, size_t count);
        uint64_t rx_mobs_tick(uint64_t world, const char* input, size_t length);
        
        void rx_random_tick_blocks(const uint32_t* states, size_t count);
        int32_t rx_random_tick(const uint64_t* chunks, size_t count, uint32_t per_subchunk, uint64_t seed, char** out, size_t* out_length);
        int32_t rx_tree_grow(int32_t kind, int32_t x, int32_t y, int32_t z, int64_t seed, const uint64_t* chunks, const int32_t* positions, size_t chunk_count, const int32_t* cleared, size_t cleared_count, const uint32_t* states, size_t state_count, const uint32_t* soil, size_t soil_count, char** out, size_t* out_length);
        
        uint64_t rx_liquid_new(void);
        void rx_liquid_free(uint64_t world);
        void rx_liquid_blocks(const uint32_t* states, const uint8_t* kinds, const uint8_t* levels, const uint32_t* extras, size_t count, const uint32_t* results);
        int32_t rx_liquid_add_chunk(uint64_t world, int32_t chunk_x, int32_t chunk_z, uint64_t chunk, uint64_t now);
        void rx_liquid_remove_chunk(uint64_t world, int32_t chunk_x, int32_t chunk_z);
        void rx_liquid_changed(uint64_t world, int32_t x, int32_t y, int32_t z, uint64_t now);
        void rx_liquid_ticking(uint64_t world, const int32_t* chunks, size_t count);
        int32_t rx_liquid_tick(uint64_t world, uint64_t now, char** out, size_t* out_length);
        
        void rx_explosion_blocks(const uint32_t* states, const float* resistances, const uint8_t* solids, size_t count, uint32_t air);
        int32_t rx_explode(const uint64_t* chunks, const int32_t* positions, size_t chunk_count, double x, double y, double z, float radius, int64_t seed, const double* boxes, size_t box_count, float* exposures, char** out, size_t* out_length);
        C;

    /** @var (FFI&NativeMethods)|null */
    private static ?FFI $ffi = null;

    private static ?string $error = "the native library was not loaded";

    /** @var array<int, Closure(string) : void> work ID => what to do with its result */
    private static array $waiting = [];

    private function __construct(){
    }

    /**
     * The file name of the library on this system.
     */
    public static function getLibraryName() : string{
        return match(PHP_OS_FAMILY){
            "Windows" => "raspberry_native.dll",
            "Darwin" => "libraspberry_native.dylib",
            default => "libraspberry_native.so"
        };
    }

    /**
     * Loads the library at $path. False when it cannot be, getError() says why.
     */
    public static function load(string $path) : bool{
        if(self::$ffi !== null){
            return true;
        }

        if(!extension_loaded("ffi")){
            self::$error = "this PHP was built without FFI";
            return false;
        }

        if(!is_file($path)){
            self::$error = "$path does not exist, build it with composer make-native";
            return false;
        }

        try{
            /** @var FFI&NativeMethods $ffi */
            $ffi = FFI::cdef(self::HEADER, $path);
            $version = $ffi->rx_version();
        }catch(Throwable $exception){
            self::$error = $exception->getMessage();
            return false;
        }

        if($version !== self::ABI_VERSION){
            self::$error = "$path is version $version but this RaspberryX needs version " . self::ABI_VERSION . ", make sure you download the updated bin!";
            return false;
        }

        self::$ffi = $ffi;
        self::$error = null;
        return true;
    }

    public static function isLoaded() : bool{
        return self::$ffi !== null;
    }

    public static function getError() : ?string{
        return self::$error;
    }

    /**
     * @return FFI&NativeMethods
     *@internal
     *
     */
    public static function get() : FFI{
        return self::$ffi ?? throw new NativeException("The native library is not available: " . self::$error);
    }

    /**
     * How much started work has not come back yet.
     */
    public static function getPendingCount() : int{
        return count(self::$waiting);
    }

    /**
     * @internal
     *
     * @param Closure(string) : void $onDone
     */
    public static function await(int $id, Closure $onDone) : void{
        self::$waiting[$id] = $onDone;
    }

    /**
     * Hands the results of finished work to whoever waits for them. The server does this every
     * tick and in between. A callback that throws is logged, the server keeps going.
     */
    public static function poll() : int{
        if(self::$ffi === null || self::$waiting === []){
            return 0;
        }

        /** @var FFI&NativeMethods $ffi */
        $ffi = self::$ffi;
        $id = $ffi->new("uint64_t");
        $out = $ffi->new("char*");
        $length = $ffi->new("size_t");
        $count = 0;

        while($ffi->rx_poll(FFI::addr($id), FFI::addr($out), FFI::addr($length)) === 1){
            $data = self::take($out, $length->cdata);
            $onDone = self::$waiting[$id->cdata] ?? null;
            unset(self::$waiting[$id->cdata]);
            ++$count;

            if($onDone === null){
                continue;
            }

            try{
                $onDone($data);
            }catch(Throwable $exception){
                Server::getInstance()->getLogger()->error("A native callback threw " . $exception::class . ": " . $exception->getMessage() . " at " . $exception->getFile() . ":" . $exception->getLine());
            }
        }

        return $count;
    }

    /**
     * @internal
     */
    public static function take(CData $buffer, int $length) : string{
        $data = $length === 0 ? "" : FFI::string($buffer, $length);
        self::get()->rx_free($buffer, $length);

        return $data;
    }
}