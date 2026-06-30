import React from 'react';
import Main from '../Layouts/Main.jsx'

const Reader = () => {
    return (
        <>
            <h1>reader</h1>
        </>
    )
}

Reader.layout = (page) => <Main children={page}/>
export default Reader;
