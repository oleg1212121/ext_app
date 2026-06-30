import React from "react";

export default function Spinner(props) {

    return (
        <>

            <div className="flex-none z-40">
                {props.pending === true ? <div
                    className="bg-green-200 dark:bg-gray-700 border-b-2 border-green-400 dark:border-gray-600 px-4 py-2.5 text-sm text-gray-800 dark:text-gray-200 flex items-center gap-2 font-medium">
                    <svg className="animate-spin h-4 w-4 text-gray-800 dark:text-gray-200"
                         xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor"
                              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>Processing...</span>
                </div> : null}
                {props.errors.length > 0 && <div
                    className="bg-red-100 dark:bg-red-900 border-b-2 border-red-300 dark:border-red-700 px-4 py-2.5 text-sm text-red-800 dark:text-red-200 flex items-center gap-2 font-medium">
                    <svg className="h-4 w-4 text-red-700 dark:text-red-300" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"
                              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>An error occurred. Please try again.</span>
                </div>}
            </div>
        </>
    )
}
