import { Head, Link } from '@inertiajs/react';

export default function Welcome({ laravelVersion, phpVersion }) {
    return (
        <>
            <Head title="Welcome" />
            <div className="min-h-screen bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 flex items-center justify-center">
                <div className="text-center">
                    <h1 className="text-6xl font-bold text-white mb-4">
                        <span className="bg-gradient-to-r from-pink-500 via-purple-500 to-indigo-500 bg-clip-text text-transparent">
                            Pecado
                        </span>
                    </h1>
                    <p className="text-xl text-gray-300 mb-8">
                        Laravel {laravelVersion} + React + Inertia + Tailwind
                    </p>
                    <div className="flex gap-4 justify-center">
                        <div className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20">
                            <div className="text-3xl font-bold text-white">Laravel</div>
                            <div className="text-gray-400">{laravelVersion}</div>
                        </div>
                        <div className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20">
                            <div className="text-3xl font-bold text-white">PHP</div>
                            <div className="text-gray-400">{phpVersion}</div>
                        </div>
                        <div className="bg-white/10 backdrop-blur-lg rounded-xl p-6 border border-white/20">
                            <div className="text-3xl font-bold text-white">React</div>
                            <div className="text-gray-400">19</div>
                        </div>
                    </div>
                    <div className="mt-8 flex gap-4 justify-center">
                        <span className="px-4 py-2 bg-pink-600/20 text-pink-400 rounded-full text-sm">
                            ✓ Inertia.js
                        </span>
                        <span className="px-4 py-2 bg-cyan-600/20 text-cyan-400 rounded-full text-sm">
                            ✓ Tailwind CSS
                        </span>
                        <span className="px-4 py-2 bg-purple-600/20 text-purple-400 rounded-full text-sm">
                            ✓ Meilisearch
                        </span>
                        <span className="px-4 py-2 bg-blue-600/20 text-blue-400 rounded-full text-sm">
                            ✓ MySQL
                        </span>
                    </div>
                </div>
            </div>
        </>
    );
}
